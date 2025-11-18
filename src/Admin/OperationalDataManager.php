<?php
/**
 * Operational Data Manager
 * 
 * Centralized management of system-tracked data (logs, statistics, flags, transients).
 * Unlike SettingsManager which handles user configuration, this manages runtime operational data.
 * 
 * @package UpstateInternational\LGL
 * @since 2.2.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;

/**
 * OperationalDataManager Class
 * 
 * Manages system-tracked data stored in individual WordPress options
 */
class OperationalDataManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Option keys
     */
    const BLOCKED_EMAILS = 'lgl_blocked_emails';
    const LAST_SYNC_DATE = 'lgl_last_sync_date';
    const LAST_SYNC_TIME = 'lgl_last_sync_time';
    const TOTAL_SYNCED_CONSTITUENTS = 'lgl_total_synced_constituents';
    const TOTAL_MEMBERSHIPS = 'lgl_total_memberships';
    const TOTAL_PAYMENTS = 'lgl_total_payments';
    const TEST_ORDERS_LAST_CLEANUP = 'lgl_test_orders_last_cleanup';
    const EMAIL_BLOCKING_DISABLED = 'lgl_email_blocking_disabled'; // Transient
    
    /**
     * Maximum blocked emails to store
     */
    const MAX_BLOCKED_EMAILS = 50;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     */
    public function __construct(Helper $helper) {
        $this->helper = $helper;
    }
    
    // ==================== Blocked Emails Log ====================
    
    /**
     * Get blocked emails log
     * 
     * @return array Array of blocked email entries
     */
    public function getBlockedEmails(): array {
        return get_option(self::BLOCKED_EMAILS, []);
    }
    
    /**
     * Add blocked email to log
     * 
     * @param array $email_data Email data to store
     * @return void
     */
    public function addBlockedEmail(array $email_data): void {
        $blocked_emails = $this->getBlockedEmails();
        
        // Keep only last N emails (rotating buffer)
        if (count($blocked_emails) >= self::MAX_BLOCKED_EMAILS) {
            $blocked_emails = array_slice($blocked_emails, -(self::MAX_BLOCKED_EMAILS - 1));
        }
        
        $blocked_emails[] = array_merge([
            'timestamp' => current_time('mysql'),
            'to' => 'Unknown',
            'subject' => 'No Subject',
            'message_preview' => '',
            'headers' => []
        ], $email_data);
        
        update_option(self::BLOCKED_EMAILS, $blocked_emails);
    }
    
    /**
     * Clear blocked emails log
     * 
     * @return void
     */
    public function clearBlockedEmails(): void {
        delete_option(self::BLOCKED_EMAILS);
        $this->helper->debug('OperationalDataManager: Cleared blocked emails log');
    }
    
    /**
     * Get blocked emails count
     * 
     * @return int Number of blocked emails in log
     */
    public function getBlockedEmailsCount(): int {
        return count($this->getBlockedEmails());
    }
    
    // ==================== Sync Statistics ====================
    
    /**
     * Get all sync statistics
     * 
     * @return array Sync statistics
     */
    public function getSyncStats(): array {
        return [
            'last_sync_date' => get_option(self::LAST_SYNC_DATE, ''),
            'last_sync_time' => get_option(self::LAST_SYNC_TIME, ''),
            'total_synced_constituents' => (int) get_option(self::TOTAL_SYNCED_CONSTITUENTS, 0),
            'total_memberships' => (int) get_option(self::TOTAL_MEMBERSHIPS, 0),
            'total_payments' => (int) get_option(self::TOTAL_PAYMENTS, 0)
        ];
    }
    
    /**
     * Update a single sync statistic
     * 
     * @param string $key Stat key (without lgl_ prefix)
     * @param mixed $value Stat value
     * @return void
     */
    public function updateSyncStat(string $key, $value): void {
        $valid_keys = [
            'last_sync_date' => self::LAST_SYNC_DATE,
            'last_sync_time' => self::LAST_SYNC_TIME,
            'total_synced_constituents' => self::TOTAL_SYNCED_CONSTITUENTS,
            'total_memberships' => self::TOTAL_MEMBERSHIPS,
            'total_payments' => self::TOTAL_PAYMENTS
        ];
        
        if (!isset($valid_keys[$key])) {
            $this->helper->debug("OperationalDataManager: Invalid sync stat key: {$key}");
            return;
        }
        
        update_option($valid_keys[$key], $value);
    }
    
    /**
     * Increment a sync counter
     * 
     * @param string $key Counter key
     * @param int $amount Amount to increment by (default 1)
     * @return void
     */
    public function incrementSyncStat(string $key, int $amount = 1): void {
        $stats = $this->getSyncStats();
        
        if (isset($stats[$key]) && is_numeric($stats[$key])) {
            $this->updateSyncStat($key, $stats[$key] + $amount);
        }
    }
    
    // ==================== Migration Flags ====================
    
    /**
     * Check if a migration has been completed
     * 
     * @param string $flag Migration flag name (without lgl_ prefix)
     * @return bool True if migration completed
     */
    public function isMigrated(string $flag): bool {
        $option_name = 'lgl_' . $flag;
        return (bool) get_option($option_name, false);
    }
    
    /**
     * Mark a migration as completed
     * 
     * @param string $flag Migration flag name (without lgl_ prefix)
     * @return void
     */
    public function setMigrated(string $flag): void {
        $option_name = 'lgl_' . $flag;
        update_option($option_name, true);
        $this->helper->debug("OperationalDataManager: Migration flag set: {$flag}");
    }
    
    // ==================== Test Cleanup Timestamp ====================
    
    /**
     * Get last test orders cleanup timestamp
     * 
     * @return int Unix timestamp
     */
    public function getLastTestCleanup(): int {
        return (int) get_option(self::TEST_ORDERS_LAST_CLEANUP, 0);
    }
    
    /**
     * Update test orders cleanup timestamp
     * 
     * @param int|null $timestamp Unix timestamp (defaults to now)
     * @return void
     */
    public function updateLastTestCleanup(?int $timestamp = null): void {
        update_option(self::TEST_ORDERS_LAST_CLEANUP, $timestamp ?? time());
    }
    
    // ==================== Temporary State (Transients) ====================
    
    /**
     * Check if email blocking is temporarily paused
     * 
     * @return bool True if paused
     */
    public function isEmailBlockingPaused(): bool {
        return get_transient(self::EMAIL_BLOCKING_DISABLED) !== false;
    }
    
    /**
     * Temporarily pause email blocking
     * 
     * @param int $duration Duration in seconds (default 300)
     * @return void
     */
    public function pauseEmailBlocking(int $duration = 300): void {
        set_transient(self::EMAIL_BLOCKING_DISABLED, true, $duration);
        $this->helper->debug("OperationalDataManager: Email blocking paused for {$duration} seconds");
    }
    
    /**
     * Resume email blocking (clear pause)
     * 
     * @return void
     */
    public function resumeEmailBlocking(): void {
        delete_transient(self::EMAIL_BLOCKING_DISABLED);
        $this->helper->debug('OperationalDataManager: Email blocking resumed');
    }
    
    // ==================== Utility Methods ====================
    
    /**
     * Get all operational data (for debugging/export)
     * 
     * @return array All operational data
     */
    public function getAllData(): array {
        return [
            'blocked_emails' => $this->getBlockedEmails(),
            'sync_stats' => $this->getSyncStats(),
            'last_test_cleanup' => $this->getLastTestCleanup(),
            'email_blocking_paused' => $this->isEmailBlockingPaused()
        ];
    }
    
    /**
     * Clear all operational data (use with caution!)
     * 
     * @return void
     */
    public function clearAll(): void {
        $this->clearBlockedEmails();
        
        $stats_keys = [
            self::LAST_SYNC_DATE,
            self::LAST_SYNC_TIME,
            self::TOTAL_SYNCED_CONSTITUENTS,
            self::TOTAL_MEMBERSHIPS,
            self::TOTAL_PAYMENTS,
            self::TEST_ORDERS_LAST_CLEANUP
        ];
        
        foreach ($stats_keys as $key) {
            delete_option($key);
        }
        
        $this->resumeEmailBlocking();
        
        $this->helper->debug('OperationalDataManager: All operational data cleared');
    }
    
    /**
     * Populate statistics from historical order data
     * 
     * Queries WooCommerce orders to calculate statistics from existing sync data.
     * This is useful for initial population or recalculation of statistics.
     * 
     * @param int|null $limit Maximum number of orders to process (null = all)
     * @return array Statistics that were calculated
     */
    public function populateHistoricalStatistics(?int $limit = null): array {
        if (!function_exists('wc_get_orders')) {
            $this->helper->debug('OperationalDataManager: WooCommerce not available for historical statistics');
            return [];
        }
        
        global $wpdb;
        
        $this->helper->debug('📊 OperationalDataManager: Starting historical statistics population', [
            'limit' => $limit
        ]);
        
        // Query orders with LGL sync status
        $meta_query = [
            [
                'key' => '_lgl_sync_status',
                'compare' => 'EXISTS'
            ]
        ];
        
        $query_args = [
            'limit' => $limit ?? -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => 'any',
            'meta_query' => $meta_query,
            'return' => 'ids'
        ];
        
        $order_ids = wc_get_orders($query_args);
        
        $stats = [
            'total_synced_constituents' => 0,
            'total_memberships' => 0,
            'total_payments' => 0,
            'last_sync_time' => '',
            'orders_processed' => 0
        ];
        
        $unique_constituents = [];
        $unique_memberships = [];
        $last_sync_timestamp = 0;
        
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                continue;
            }
            
            $sync_status = $order->get_meta('_lgl_sync_status');
            if (!$sync_status || ($sync_status !== 'synced' && $sync_status !== 'partial')) {
                continue;
            }
            
            $stats['orders_processed']++;
            
            // Count unique constituents (by LGL ID)
            $lgl_id = $order->get_meta('_lgl_lgl_id');
            if ($lgl_id && !in_array($lgl_id, $unique_constituents, true)) {
                $unique_constituents[] = $lgl_id;
                $stats['total_synced_constituents']++;
            }
            
            // Check if order contains membership products
            $items = $order->get_items();
            $has_membership = false;
            foreach ($items as $item) {
                $product_id = $item->get_variation_id() ?: $item->get_product_id();
                $parent_id = $item->get_variation_id() ? $item->get_product_id() : $product_id;
                if (has_term('memberships', 'product_cat', $parent_id)) {
                    $has_membership = true;
                    break;
                }
            }
            
            if ($has_membership) {
                $membership_key = $order->get_customer_id() . '_' . $order->get_date_created()->format('Y-m-d');
                if (!in_array($membership_key, $unique_memberships, true)) {
                    $unique_memberships[] = $membership_key;
                    $stats['total_memberships']++;
                }
            }
            
            // Count payments (by payment ID)
            $payment_id = $order->get_meta('_lgl_payment_id');
            if ($payment_id) {
                $stats['total_payments']++;
            }
            
            // Track latest sync time
            $processed_at = $order->get_meta('_lgl_processed_at');
            if ($processed_at) {
                $timestamp = strtotime($processed_at);
                if ($timestamp > $last_sync_timestamp) {
                    $last_sync_timestamp = $timestamp;
                    $stats['last_sync_time'] = $processed_at;
                }
            }
        }
        
        // Update statistics
        $this->updateSyncStat('total_synced_constituents', $stats['total_synced_constituents']);
        $this->updateSyncStat('total_memberships', $stats['total_memberships']);
        $this->updateSyncStat('total_payments', $stats['total_payments']);
        if ($stats['last_sync_time']) {
            $this->updateSyncStat('last_sync_time', $stats['last_sync_time']);
            $this->updateSyncStat('last_sync_date', date('Y-m-d', $last_sync_timestamp));
        }
        
        $this->helper->debug('✅ OperationalDataManager: Historical statistics populated', $stats);
        
        return $stats;
    }
}

