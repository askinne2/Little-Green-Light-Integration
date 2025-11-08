<?php
/**
 * Membership Migration Utility
 * 
 * Handles one-time migration of existing members to set renewal dates.
 * Determines which members should be managed by plugin vs WC Subscriptions.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Memberships\RenewalStrategyManager;

/**
 * MembershipMigrationUtility Class
 * 
 * Manages migration of existing members to dual renewal system
 */
class MembershipMigrationUtility {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Renewal strategy manager
     * 
     * @var RenewalStrategyManager
     */
    private RenewalStrategyManager $strategyManager;
    
    /**
     * Migration flag option name
     */
    const MIGRATION_FLAG = 'lgl_renewal_migration_completed';
    
    /**
     * Constructor
     * 
     * @param RenewalStrategyManager $strategyManager Renewal strategy manager
     * @param Helper $helper Helper service
     */
    public function __construct(
        RenewalStrategyManager $strategyManager,
        Helper $helper
    ) {
        $this->strategyManager = $strategyManager;
        $this->helper = $helper;
        
        // Register shortcode for admin-triggered migration
        add_shortcode('lgl_migrate_members', [$this, 'renderMigrationShortcode']);
    }
    
    /**
     * Migrate existing members to set renewal dates
     * 
     * @param bool $force Force migration even if already completed
     * @return array Migration results
     */
    public function migrateExistingMembers(bool $force = false): array {
        $this->helper->debug('MembershipMigration: Starting migration process');
        
        $results = [
            'processed' => 0,
            'migrated' => 0,
            'skipped' => 0,
            'wc_subscription' => 0,
            'details' => [],
            'errors' => []
        ];
        
        // Check if migration already completed
        if (!$force && get_option(self::MIGRATION_FLAG)) {
            $results['details'][] = 'Migration already completed. Use force=true to re-run.';
            return $results;
        }
        
        // Get all members
        $members = get_users(['role__in' => ['ui_member', 'ui_patron_owner']]);
        $this->helper->debug("MembershipMigration: Found {count} members to process", ['count' => count($members)]);
        
        foreach ($members as $member) {
            $results['processed']++;
            
            try {
                $result = $this->processMemberMigration($member->ID);
                
                if ($result['action'] === 'migrated') {
                    $results['migrated']++;
                } elseif ($result['action'] === 'wc_subscription') {
                    $results['wc_subscription']++;
                } else {
                    $results['skipped']++;
                }
                
                $results['details'][] = $result['message'];
                
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'user_id' => $member->ID,
                    'error' => $e->getMessage()
                ];
                $this->helper->debug("MembershipMigration: Error processing user {$member->ID}: {$e->getMessage()}");
            }
        }
        
        // Mark migration as complete
        if (!$force) {
            update_option(self::MIGRATION_FLAG, time());
        }
        
        $this->helper->debug('MembershipMigration: Completed', $results);
        return $results;
    }
    
    /**
     * Process migration for a single member
     * 
     * @param int $user_id User ID
     * @return array Processing result
     */
    private function processMemberMigration(int $user_id): array {
        // Skip if already has renewal date set (and not forcing)
        $existing_renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
        if ($existing_renewal_date) {
            return [
                'action' => 'skipped',
                'message' => "User {$user_id}: Already has renewal date set"
            ];
        }
        
        // Check for WC subscription
        if ($this->strategyManager->userHasActiveSubscription($user_id)) {
            // Has subscription - mark but don't set renewal date (WC handles it)
            update_user_meta($user_id, 'user-subscription-status', 'wc-subscription');
            
            return [
                'action' => 'wc_subscription',
                'message' => "User {$user_id}: WC Subscription detected, renewal managed by WooCommerce"
            ];
        }
        
        // No subscription - find last membership order
        $last_order_date = $this->getLastMembershipOrderDate($user_id);
        
        if (!$last_order_date) {
            return [
                'action' => 'skipped',
                'message' => "User {$user_id}: No membership orders found"
            ];
        }
        
        // Set renewal date to 1 year from last order
        $renewal_date = strtotime('+1 year', $last_order_date);
        update_user_meta($user_id, 'user-membership-renewal-date', $renewal_date);
        update_user_meta($user_id, 'user-membership-start-date', $last_order_date);
        update_user_meta($user_id, 'user-subscription-status', 'one-time');
        
        return [
            'action' => 'migrated',
            'message' => sprintf(
                "User %d: Set renewal to %s (from order date %s)",
                $user_id,
                date('Y-m-d', $renewal_date),
                date('Y-m-d', $last_order_date)
            )
        ];
    }
    
    /**
     * Get last membership order date for user
     * 
     * @param int $user_id User ID
     * @return int|null Unix timestamp or null if not found
     */
    private function getLastMembershipOrderDate(int $user_id): ?int {
        if (!function_exists('wc_get_orders')) {
            return null;
        }
        
        // Get completed orders for this user
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'status' => ['completed', 'processing'],
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        // Find most recent membership order
        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $product = $item->get_product();
                if (!$product) {
                    continue;
                }
                
                // Check if product is in membership category
                $product_id = $product->get_parent_id() ?: $product->get_id();
                if (has_term('memberships', 'product_cat', $product_id)) {
                    return $order->get_date_created()->getTimestamp();
                }
            }
        }
        
        return null;
    }
    
    /**
     * Check if migration has been completed
     * 
     * @return bool
     */
    public function isMigrationCompleted(): bool {
        return (bool) get_option(self::MIGRATION_FLAG);
    }
    
    /**
     * Reset migration flag
     * 
     * @return void
     */
    public function resetMigrationFlag(): void {
        delete_option(self::MIGRATION_FLAG);
        $this->helper->debug('MembershipMigration: Migration flag reset');
    }
    
    /**
     * Get migration status
     * 
     * @return array Status information
     */
    public function getMigrationStatus(): array {
        $completed = $this->isMigrationCompleted();
        $completed_time = get_option(self::MIGRATION_FLAG);
        
        return [
            'completed' => $completed,
            'completed_at' => $completed_time ? date('Y-m-d H:i:s', $completed_time) : null,
            'can_run' => current_user_can('manage_options')
        ];
    }
    
    /**
     * Render migration shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string HTML output
     */
    public function renderMigrationShortcode($atts): string {
        // Check permissions
        if (!current_user_can('manage_options')) {
            return '<div class="notice notice-error"><p><strong>Access Denied:</strong> Admin only</p></div>';
        }
        
        $atts = shortcode_atts([
            'confirm' => 'no',
            'force' => 'no'
        ], $atts);
        
        // Check if confirmed
        if ($atts['confirm'] !== 'yes') {
            return $this->renderMigrationConfirmation();
        }
        
        // Run migration
        $force = $atts['force'] === 'yes';
        $results = $this->migrateExistingMembers($force);
        
        return $this->renderMigrationResults($results);
    }
    
    /**
     * Render migration confirmation screen
     * 
     * @return string HTML output
     */
    private function renderMigrationConfirmation(): string {
        $status = $this->getMigrationStatus();
        $stats = $this->strategyManager->getRenewalStatistics();
        
        ob_start();
        ?>
        <div style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2 style="margin-top: 0;">Membership Renewal Migration</h2>
            
            <?php if ($status['completed']): ?>
                <div style="padding: 15px; background: #d4edda; border-left: 4px solid #28a745; margin-bottom: 20px;">
                    <p><strong>Migration Already Completed</strong></p>
                    <p>Last run: <?php echo esc_html($status['completed_at']); ?></p>
                    <p>You can re-run the migration by adding <code>force="yes"</code> to the shortcode.</p>
                </div>
            <?php endif; ?>
            
            <h3>Current Statistics</h3>
            <table style="width: 100%; max-width: 600px; border-collapse: collapse; margin-bottom: 20px;">
                <tr style="background: #f9f9f9;">
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Total Members:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($stats['total_members']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>WC Managed:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($stats['wc_managed']); ?></td>
                </tr>
                <tr style="background: #f9f9f9;">
                    <td style="padding: 10px; border: 1px solid #ddd;"><strong>Plugin Managed:</strong></td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($stats['plugin_managed']); ?></td>
                </tr>
            </table>
            
            <h3>What This Migration Does:</h3>
            <ul>
                <li>Analyzes all members to determine renewal strategy</li>
                <li>Members with active WC subscriptions: marked as "wc-subscription"</li>
                <li>Members without subscriptions: set renewal date based on last order</li>
                <li>Members with existing renewal dates: skipped</li>
                <li>Safe to run multiple times (idempotent)</li>
            </ul>
            
            <p style="margin-top: 30px;">
                <a href="<?php echo esc_url(add_query_arg('confirm', 'yes')); ?>" 
                   class="button button-primary button-large"
                   style="padding: 10px 30px; height: auto; font-size: 16px;">
                    ✓ Confirm and Run Migration
                </a>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render migration results
     * 
     * @param array $results Migration results
     * @return string HTML output
     */
    private function renderMigrationResults(array $results): string {
        ob_start();
        ?>
        <div style="padding: 20px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h2 style="margin-top: 0;">✓ Migration Complete</h2>
            
            <table style="width: 100%; max-width: 600px; border-collapse: collapse; margin: 20px 0;">
                <tr style="background: #f9f9f9;">
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 600;">Total Processed:</td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($results['processed']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 600;">Migrated (One-Time):</td>
                    <td style="padding: 10px; border: 1px solid #ddd; color: #28a745; font-weight: 600;">
                        <?php echo esc_html($results['migrated']); ?>
                    </td>
                </tr>
                <tr style="background: #f9f9f9;">
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 600;">WC Subscriptions:</td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($results['wc_subscription']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 600;">Skipped:</td>
                    <td style="padding: 10px; border: 1px solid #ddd;"><?php echo esc_html($results['skipped']); ?></td>
                </tr>
                <?php if (!empty($results['errors'])): ?>
                <tr style="background: #f8d7da;">
                    <td style="padding: 10px; border: 1px solid #ddd; font-weight: 600;">Errors:</td>
                    <td style="padding: 10px; border: 1px solid #ddd; color: #dc3545; font-weight: 600;">
                        <?php echo esc_html(count($results['errors'])); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if (!empty($results['details']) && count($results['details']) < 50): ?>
                <h3>Details:</h3>
                <div style="background: #f9f9f9; padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto;">
                    <?php foreach ($results['details'] as $detail): ?>
                        <p style="margin: 5px 0; font-family: monospace; font-size: 12px;">
                            <?php echo esc_html($detail); ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($results['errors'])): ?>
                <h3 style="color: #dc3545;">Errors:</h3>
                <div style="background: #f8d7da; padding: 15px; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                    <?php foreach ($results['errors'] as $error): ?>
                        <p style="margin: 5px 0; font-family: monospace; font-size: 12px;">
                            User <?php echo esc_html($error['user_id']); ?>: <?php echo esc_html($error['error']); ?>
                        </p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                <em>Check error logs for detailed information about each member processed.</em>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}

