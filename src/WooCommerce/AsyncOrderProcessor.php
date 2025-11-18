<?php
/**
 * Async Order Processor
 * 
 * Handles asynchronous LGL API processing for WooCommerce orders.
 * Processes LGL sync in background to speed up checkout flow.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;

/**
 * AsyncOrderProcessor Class
 * 
 * Handles background processing of LGL API calls for orders
 */
class AsyncOrderProcessor {
    
    /**
     * Cron hook name for async processing
     */
    const CRON_HOOK = 'lgl_process_order_async';
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Order Processor instance
     * 
     * @var OrderProcessor|null
     */
    private ?OrderProcessor $orderProcessor = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param OrderProcessor|null $orderProcessor Order processor instance (optional, can be set later)
     */
    public function __construct(
        Helper $helper,
        ?OrderProcessor $orderProcessor = null
    ) {
        $this->helper = $helper;
        $this->orderProcessor = $orderProcessor;
        
        // Register WP Cron handler (more reliable than HTTP postback)
        add_action(self::CRON_HOOK, [$this, 'handleAsyncRequest'], 10, 1);
        
        // Register fallback mechanism to check for stuck orders
        // This ensures processing happens even if WP Cron doesn't fire
        add_action('admin_init', [$this, 'checkStuckOrders'], 999);
        add_action('wp_loaded', [$this, 'checkStuckOrders'], 999);
    }
    
    /**
     * Set order processor (for circular dependency resolution)
     * 
     * @param OrderProcessor $orderProcessor Order processor instance
     * @return void
     */
    public function setOrderProcessor(OrderProcessor $orderProcessor): void {
        $this->orderProcessor = $orderProcessor;
    }
    
    /**
     * Schedule async LGL processing for an order
     * 
     * Uses WP Cron to process LGL sync in background (more reliable than HTTP postback)
     * 
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function scheduleAsyncProcessing(int $order_id): void {
        $this->helper->debug('⏰ AsyncOrderProcessor: Scheduling async processing (WP Cron)', [
            'order_id' => $order_id
        ]);
        
        // Mark order as queued for async processing
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_lgl_async_queued', true);
            $order->update_meta_data('_lgl_async_queued_at', current_time('mysql'));
            $order->save();
        }
        
        // Check if DISABLE_WP_CRON is set
        $cron_disabled = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
        $this->helper->debug('🔍 AsyncOrderProcessor: Cron status check', [
            'order_id' => $order_id,
            'DISABLE_WP_CRON' => $cron_disabled,
            'has_order_processor' => !is_null($this->orderProcessor)
        ]);
        
        // Schedule WP Cron event to run immediately (on next page load)
        // This is more reliable than HTTP postback and doesn't require nonces/sessions
        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK, [$order_id]);
        
        if ($scheduled === false) {
            // Check if already scheduled (prevents duplicates)
            $next_scheduled = wp_next_scheduled(self::CRON_HOOK, [$order_id]);
            if ($next_scheduled) {
                $this->helper->debug('⚠️ AsyncOrderProcessor: Cron already scheduled', [
                    'order_id' => $order_id,
                    'next_run' => date('Y-m-d H:i:s', $next_scheduled)
                ]);
            } else {
                $this->helper->debug('❌ AsyncOrderProcessor: Failed to schedule cron', [
                    'order_id' => $order_id
                ]);
            }
        } else {
            $this->helper->debug('✅ AsyncOrderProcessor: WP Cron event scheduled', [
                'order_id' => $order_id,
                'hook' => self::CRON_HOOK,
                'scheduled_for' => date('Y-m-d H:i:s', time())
            ]);
            
            // Trigger cron immediately (non-blocking) to ensure it runs
            // This uses WordPress's spawn_cron() which makes a non-blocking HTTP request
            if (!$cron_disabled) {
                spawn_cron();
                $this->helper->debug('🚀 AsyncOrderProcessor: Triggered cron spawn for immediate execution', [
                    'order_id' => $order_id
                ]);
            } else {
                $this->helper->debug('⚠️ AsyncOrderProcessor: WP Cron disabled, will run on next page load', [
                    'order_id' => $order_id
                ]);
            }
        }
    }
    
    /**
     * Check for orders that are queued but not yet processed
     * Fallback mechanism if WP Cron doesn't fire
     * 
     * @return void
     */
    public function checkStuckOrders(): void {
        // Only check occasionally (every 30 seconds) to avoid performance issues
        $last_check = get_transient('lgl_async_order_check');
        if ($last_check && (time() - $last_check) < 30) {
            return;
        }
        
        set_transient('lgl_async_order_check', time(), 60);
        
        // Find orders queued more than 1 minute ago but not processed
        $args = [
            'limit' => 10,
            'status' => 'any',
            'date_created' => '>' . (time() - DAY_IN_SECONDS),
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_lgl_async_queued',
                    'value' => '1',
                    'compare' => '='
                ],
                [
                    'key' => '_lgl_async_processed',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => '_lgl_async_permanently_failed',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];
        
        $queued_orders = wc_get_orders($args);
        
        if (!empty($queued_orders)) {
            foreach ($queued_orders as $order) {
                $queued_at = $order->get_meta('_lgl_async_queued_at');
                if ($queued_at) {
                    $queued_timestamp = strtotime($queued_at);
                    $minutes_ago = (time() - $queued_timestamp) / 60;
                    
                    // Process if queued more than 1 minute ago
                    if ($minutes_ago > 1) {
                        $this->helper->debug('🔧 AsyncOrderProcessor: Found stuck order, processing now', [
                            'order_id' => $order->get_id(),
                            'queued_at' => $queued_at,
                            'minutes_ago' => round($minutes_ago, 2)
                        ]);
                        
                        // Process directly (bypass cron)
                        $this->handleAsyncRequest($order->get_id());
                    }
                }
            }
        }
    }
    
    /**
     * Handle async request (called by WP Cron)
     * 
     * @param int $order_id WooCommerce order ID (passed by WP Cron)
     * @return void
     */
    public function handleAsyncRequest(int $order_id): void {
        $this->helper->debug('🔄 AsyncOrderProcessor: Processing order async (WP Cron)', [
            'order_id' => $order_id,
            'timestamp' => current_time('mysql'),
            'cron_hook' => self::CRON_HOOK
        ]);
        
        if (!$order_id || $order_id <= 0) {
            $this->helper->debug('❌ AsyncOrderProcessor: Invalid order ID', [
                'order_id' => $order_id
            ]);
            return;
        }
        
        if (!$this->orderProcessor) {
            $this->helper->debug('❌ AsyncOrderProcessor: OrderProcessor not available', [
                'order_id' => $order_id
            ]);
            return;
        }
        
        // Check if already processed (prevent duplicate processing)
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->helper->debug('❌ AsyncOrderProcessor: Order not found', [
                'order_id' => $order_id
            ]);
            return;
        }
        
        // Check if permanently failed (don't retry)
        $permanently_failed = $order->get_meta('_lgl_async_permanently_failed');
        if ($permanently_failed) {
            $this->helper->debug('⏹️ AsyncOrderProcessor: Order permanently failed, skipping', [
                'order_id' => $order_id,
                'failed_reason' => $order->get_meta('_lgl_async_permanent_failure_reason')
            ]);
            return;
        }
        
        $already_processed = $order->get_meta('_lgl_async_processed');
        if ($already_processed) {
            $this->helper->debug('⏭️ AsyncOrderProcessor: Order already processed, skipping', [
                'order_id' => $order_id,
                'processed_at' => $order->get_meta('_lgl_async_processed_at')
            ]);
            return;
        }
        
        // Check if user exists (critical validation)
        $user_id = $order->get_customer_id();
        
        // Check what products are in this order to determine if user_id is required
        $order_items = $order->get_items();
        $has_membership_products = false;
        $has_products_requiring_user = false;
        
        foreach ($order_items as $item) {
            $product_id = $item->get_variation_id() ?: $item->get_product_id();
            $parent_id = $item->get_variation_id() ? $item->get_product_id() : $product_id;
            
            // Check if this is a membership product (requires user_id)
            if (has_term('memberships', 'product_cat', $parent_id)) {
                $has_membership_products = true;
                $has_products_requiring_user = true;
                break;
            }
        }
        
        // If order has products requiring user_id but user_id is 0 or user doesn't exist
        if ($has_products_requiring_user) {
            if ($user_id <= 0) {
                // Membership products require a user - mark as permanently failed
                $order->update_meta_data('_lgl_async_permanently_failed', true);
                $order->update_meta_data('_lgl_async_permanent_failure_reason', 'Membership products require a valid user_id, but customer_id is 0');
                $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                $order->save();
                
                $this->helper->debug('🛑 AsyncOrderProcessor: Membership product requires user_id but customer_id is 0', [
                    'order_id' => $order_id,
                    'has_membership_products' => true
                ]);
                return;
            }
            
            // User ID exists - verify user still exists
            $user = get_user_by('id', $user_id);
            if (!$user) {
                // User was deleted - mark as permanently failed
                $order->update_meta_data('_lgl_async_permanently_failed', true);
                $order->update_meta_data('_lgl_async_permanent_failure_reason', 'User deleted from WordPress');
                $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                $order->save();
                
                $this->helper->debug('🛑 AsyncOrderProcessor: User deleted, marking as permanently failed', [
                    'order_id' => $order_id,
                    'user_id' => $user_id
                ]);
                return;
            }
        } else {
            // For non-membership products, check if user exists (if user_id > 0)
            if ($user_id > 0) {
                $user = get_user_by('id', $user_id);
                if (!$user) {
                    // User was deleted - mark as permanently failed
                    $order->update_meta_data('_lgl_async_permanently_failed', true);
                    $order->update_meta_data('_lgl_async_permanent_failure_reason', 'User deleted from WordPress');
                    $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                    $order->save();
                    
                    $this->helper->debug('🛑 AsyncOrderProcessor: User deleted, marking as permanently failed', [
                        'order_id' => $order_id,
                        'user_id' => $user_id
                    ]);
                    return;
                }
            } else {
                // No customer ID for non-membership products - check if billing email exists
                $billing_email = $order->get_billing_email();
                if (empty($billing_email)) {
                    // No user and no email - mark as permanently failed
                    $order->update_meta_data('_lgl_async_permanently_failed', true);
                    $order->update_meta_data('_lgl_async_permanent_failure_reason', 'No customer ID and no billing email');
                    $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                    $order->save();
                    
                    $this->helper->debug('🛑 AsyncOrderProcessor: No customer ID or email, marking as permanently failed', [
                        'order_id' => $order_id
                    ]);
                    return;
                }
            }
        }
        
        // Check retry count (max 5 retries = ~25 minutes total)
        $retry_count = (int) $order->get_meta('_lgl_async_retry_count');
        $max_retries = 5;
        
        if ($retry_count >= $max_retries) {
            // Max retries reached - mark as permanently failed
            $order->update_meta_data('_lgl_async_permanently_failed', true);
            $order->update_meta_data('_lgl_async_permanent_failure_reason', "Max retries reached ({$max_retries})");
            $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
            $order->save();
            
            // Unschedule any pending cron events
            wp_unschedule_event(wp_next_scheduled(self::CRON_HOOK, [$order_id]), self::CRON_HOOK, [$order_id]);
            
            $this->helper->debug('🛑 AsyncOrderProcessor: Max retries reached, marking as permanently failed', [
                'order_id' => $order_id,
                'retry_count' => $retry_count
            ]);
            return;
        }
        
        try {
            // Process LGL sync only (user data already saved)
            $this->orderProcessor->processLglSyncOnly($order_id);
            
            // Mark as processed
            $order->update_meta_data('_lgl_async_processed', true);
            $order->update_meta_data('_lgl_async_processed_at', current_time('mysql'));
            $order->delete_meta_data('_lgl_async_retry_count'); // Clear retry count on success
            $order->delete_meta_data('_lgl_async_failed');
            $order->delete_meta_data('_lgl_async_error');
            $order->save();
            
            $this->helper->debug('✅ AsyncOrderProcessor: Async processing completed', [
                'order_id' => $order_id
            ]);
            
        } catch (\Exception $e) {
            $retry_count++;
            $error_message = $e->getMessage();
            
            $this->helper->debug('❌ AsyncOrderProcessor: Async processing failed', [
                'order_id' => $order_id,
                'error' => $error_message,
                'retry_count' => $retry_count,
                'max_retries' => $max_retries,
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Check if error is non-retryable (user-related errors)
            $non_retryable_errors = [
                'requires a valid user_id',
                'User deleted',
                'No customer ID',
                'user not found'
            ];
            
            $is_non_retryable = false;
            foreach ($non_retryable_errors as $pattern) {
                if (stripos($error_message, $pattern) !== false) {
                    $is_non_retryable = true;
                    break;
                }
            }
            
            if ($is_non_retryable || $retry_count >= $max_retries) {
                // Mark as permanently failed
                $order->update_meta_data('_lgl_async_permanently_failed', true);
                $order->update_meta_data('_lgl_async_permanent_failure_reason', $is_non_retryable 
                    ? 'Non-retryable error: ' . $error_message 
                    : "Max retries reached ({$max_retries})");
                $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                $order->save();
                
                // Unschedule any pending cron events
                $next_scheduled = wp_next_scheduled(self::CRON_HOOK, [$order_id]);
                if ($next_scheduled) {
                    wp_unschedule_event($next_scheduled, self::CRON_HOOK, [$order_id]);
                }
                
                $this->helper->debug('🛑 AsyncOrderProcessor: Marking as permanently failed', [
                    'order_id' => $order_id,
                    'reason' => $is_non_retryable ? 'Non-retryable error' : 'Max retries',
                    'error' => $error_message
                ]);
            } else {
                // Mark as failed for retry
                $order->update_meta_data('_lgl_async_failed', true);
                $order->update_meta_data('_lgl_async_error', $error_message);
                $order->update_meta_data('_lgl_async_failed_at', current_time('mysql'));
                $order->update_meta_data('_lgl_async_retry_count', $retry_count);
                $order->save();
                
                // Re-schedule for retry (in 5 minutes)
                wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_HOOK, [$order_id]);
                
                $this->helper->debug('🔄 AsyncOrderProcessor: Re-scheduled for retry', [
                    'order_id' => $order_id,
                    'retry_count' => $retry_count,
                    'retry_in' => '5 minutes'
                ]);
            }
        }
    }
    
    /**
     * Clear permanently failed status for an order (admin utility)
     * 
     * @param int $order_id Order ID
     * @return bool Success status
     */
    public function clearPermanentFailure(int $order_id): bool {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $order->delete_meta_data('_lgl_async_permanently_failed');
        $order->delete_meta_data('_lgl_async_permanent_failure_reason');
        $order->delete_meta_data('_lgl_async_retry_count');
        $order->save();
        
        // Unschedule any pending cron events
        $next_scheduled = wp_next_scheduled(self::CRON_HOOK, [$order_id]);
        if ($next_scheduled) {
            wp_unschedule_event($next_scheduled, self::CRON_HOOK, [$order_id]);
        }
        
        $this->helper->debug('🔓 AsyncOrderProcessor: Cleared permanent failure status', [
            'order_id' => $order_id
        ]);
        
        return true;
    }
    
    /**
     * Get processing status for an order
     * 
     * @param int $order_id Order ID
     * @return array Status information
     */
    public function getOrderStatus(int $order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            return ['error' => 'Order not found'];
        }
        
        $status = [
            'order_id' => $order_id,
            'queued' => (bool) $order->get_meta('_lgl_async_queued'),
            'queued_at' => $order->get_meta('_lgl_async_queued_at'),
            'processed' => (bool) $order->get_meta('_lgl_async_processed'),
            'processed_at' => $order->get_meta('_lgl_async_processed_at'),
            'failed' => (bool) $order->get_meta('_lgl_async_failed'),
            'failed_at' => $order->get_meta('_lgl_async_failed_at'),
            'error' => $order->get_meta('_lgl_async_error'),
            'permanently_failed' => (bool) $order->get_meta('_lgl_async_permanently_failed'),
            'permanent_failure_reason' => $order->get_meta('_lgl_async_permanent_failure_reason'),
            'retry_count' => (int) $order->get_meta('_lgl_async_retry_count'),
            'next_scheduled' => wp_next_scheduled(self::CRON_HOOK, [$order_id]),
            'customer_id' => $order->get_customer_id(),
            'user_exists' => false
        ];
        
        // Check if user exists
        $user_id = $order->get_customer_id();
        if ($user_id > 0) {
            $status['user_exists'] = (bool) get_user_by('id', $user_id);
        }
        
        return $status;
    }
}

