<?php
/**
 * LGL WordPress Users Manager
 * 
 * Manages WordPress user integration with Little Green Light.
 * Handles user synchronization, membership updates, and user lifecycle management.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\Core\CacheManager;

/**
 * WP Users Class
 * 
 * Manages WordPress user integration with LGL
 */
class WpUsers {
    
    /**
     * Class instance
     * 
     * @var WpUsers|null
     */
    private static $instance = null;
    
    /**
     * API Settings instance
     * 
     * @var ApiSettings
     */
    private $lgl;
    
    /**
     * Cron hook names
     */
    const MONTHLY_UPDATE_HOOK = 'lgl_monthly_user_update';
    const USER_DELETION_HOOK = 'lgl_user_deletion';
    
    /**
     * Get instance
     * 
     * @return WpUsers
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        $this->lgl = ApiSettings::getInstance();
        $this->initializeUserManager();
    }
    
    /**
     * Initialize user manager
     */
    private function initializeUserManager(): void {
        // Register shortcodes
        add_action('init', [$this, 'initializeShortcodes']);
        
        // Register cron jobs
        $this->registerCronJobs();
        
        // Register dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'registerSyncDashboardWidget']);
        
        // error_log('LGL WP Users: Initialized successfully');
    }
    
    /**
     * Initialize shortcodes
     */
    public function initializeShortcodes(): void {
        add_shortcode('lgl_user_sync', [$this, 'userSyncShortcode']);
        add_shortcode('lgl_monthly_update', [$this, 'monthlyUpdateShortcode']);
        add_shortcode('lgl_user_deletion', [$this, 'userDeletionShortcode']);
    }
    
    /**
     * Register cron jobs
     */
    private function registerCronJobs(): void {
        // Schedule monthly update if not already scheduled
        if (!wp_next_scheduled(static::MONTHLY_UPDATE_HOOK)) {
            wp_schedule_event(time(), 'monthly', static::MONTHLY_UPDATE_HOOK);
        }
        
        // Schedule user deletion check if not already scheduled
        if (!wp_next_scheduled(static::USER_DELETION_HOOK)) {
            wp_schedule_event(time(), 'daily', static::USER_DELETION_HOOK);
        }
        
        // Register cron actions
        add_action(static::MONTHLY_UPDATE_HOOK, [$this, 'runMonthlyUpdate']);
        add_action(static::USER_DELETION_HOOK, [$this, 'userDeletion']);
    }
    
    /**
     * Run monthly user update
     * 
     * @return string Status message
     */
    public function runMonthlyUpdate(): string {
        try {
            $users = get_users([
                'meta_key' => 'lgl_constituent_id',
                'meta_compare' => 'EXISTS'
            ]);
            
            $updated_count = 0;
            $errors = [];
            
            foreach ($users as $user) {
                try {
                    $result = $this->syncUserWithLgl($user->ID);
                    if ($result['success']) {
                        $updated_count++;
                    } else {
                        $errors[] = 'User ' . $user->ID . ': ' . ($result['error'] ?? 'Unknown error');
                    }
                } catch (\Exception $e) {
                    $errors[] = 'User ' . $user->ID . ': ' . $e->getMessage();
                }
            }
            
            $message = "Monthly update completed. Updated {$updated_count} users.";
            if (!empty($errors)) {
                $message .= " Errors: " . implode(', ', array_slice($errors, 0, 5));
                if (count($errors) > 5) {
                    $message .= " (and " . (count($errors) - 5) . " more)";
                }
            }
            
            error_log('LGL WP Users: ' . $message);
            return $message;
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Monthly update failed: ' . $e->getMessage());
            return 'Monthly update failed: ' . $e->getMessage();
        }
    }
    
    /**
     * User deletion cleanup
     * 
     * @return string Status message
     */
    public function userDeletion(): string {
        try {
            // Find users marked for deletion or inactive for extended periods
            $users_to_check = get_users([
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => 'user_deletion_scheduled',
                        'value' => '1',
                        'compare' => '='
                    ],
                    [
                        'key' => 'last_login',
                        'value' => date('Y-m-d', strtotime('-2 years')),
                        'compare' => '<',
                        'type' => 'DATE'
                    ]
                ]
            ]);
            
            $processed_count = 0;
            $deleted_count = 0;
            
            foreach ($users_to_check as $user) {
                $processed_count++;
                
                // Check if user should be deleted
                if ($this->shouldDeleteUser($user->ID)) {
                    $result = $this->processUserDeletion($user->ID);
                    if ($result['success']) {
                        $deleted_count++;
                    }
                }
            }
            
            $message = "User deletion check completed. Processed {$processed_count} users, deleted {$deleted_count}.";
            error_log('LGL WP Users: ' . $message);
            return $message;
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: User deletion failed: ' . $e->getMessage());
            return 'User deletion failed: ' . $e->getMessage();
        }
    }
    
    /**
     * Update user data from order
     * 
     * @param array $request Request data
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @return array Update result
     */
    public function updateUserData(array $request, \WC_Order $order, array $order_meta): array {
        try {
            $user_id = $order->get_customer_id();
            if (!$user_id) {
                throw new \Exception('No customer ID found for order');
            }
            
            // Update user meta from order
            $this->updateUserMetaFromOrder($user_id, $order, $order_meta);
            
            // Update subscription info if applicable
            $this->updateUserSubscriptionInfo($user_id, $order->get_id());
            
            // Sync with LGL
            $sync_result = $this->syncUserWithLgl($user_id);
            
            return [
                'success' => true,
                'user_id' => $user_id,
                'lgl_sync' => $sync_result,
                'message' => 'User data updated successfully'
            ];
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error updating user data: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update user subscription information
     * 
     * @param int $user_id WordPress user ID
     * @param int $order_id WooCommerce order ID
     * @return array Update result
     */
    public function updateUserSubscriptionInfo(int $user_id, int $order_id): array {
        try {
            if (!function_exists('wcs_get_subscriptions_for_order')) {
                return ['success' => false, 'error' => 'WooCommerce Subscriptions not available'];
            }
            
            $subscriptions = wcs_get_subscriptions_for_order($order_id);
            
            if (empty($subscriptions)) {
                return ['success' => true, 'message' => 'No subscriptions found for order'];
            }
            
            $updated_subscriptions = [];
            
            foreach ($subscriptions as $subscription) {
                $subscription_data = [
                    'subscription_id' => $subscription->get_id(),
                    'status' => $subscription->get_status(),
                    'next_payment' => $subscription->get_date('next_payment'),
                    'end_date' => $subscription->get_date('end'),
                    'total' => $subscription->get_total()
                ];
                
                // Update user meta
                update_user_meta($user_id, 'user-subscription-id', $subscription_data['subscription_id']);
                update_user_meta($user_id, 'user-subscription-status', $subscription_data['status']);
                update_user_meta($user_id, 'user-membership-renewal-date', $subscription_data['next_payment']);
                
                $updated_subscriptions[] = $subscription_data;
            }
            
            return [
                'success' => true,
                'subscriptions' => $updated_subscriptions,
                'message' => 'Subscription info updated for ' . count($updated_subscriptions) . ' subscriptions'
            ];
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error updating subscription info: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create event registration CCT (Custom Content Type)
     * 
     * @param \WC_Order $order WooCommerce order
     * @param array $attendees Attendee information
     * @return array Creation result
     */
    public function createEventRegistrationCct(\WC_Order $order, array $attendees): array {
        try {
            if (!function_exists('jet_engine') || !class_exists('Jet_Engine_CPT')) {
                return ['success' => false, 'error' => 'JetEngine not available'];
            }
            
            $created_registrations = [];
            
            foreach ($attendees as $attendee) {
                // Extract attendee data (handle both old and new formats)
                $attendee_name = $attendee['attendee_name'] ?? $attendee['name'] ?? '';
                $attendee_email = $attendee['attendee_email'] ?? $attendee['email'] ?? '';
                $attendee_phone = $attendee['attendee_phone'] ?? $attendee['phone'] ?? '';
                $product_id = $attendee['product_id'] ?? 0;
                $parent_id = $attendee['parent_id'] ?? 0;
                $variation_name = $attendee['variation_name'] ?? '';
                
                // JetEngine CCT data (without _ID means create new)
                $registration_data = [
                    'order_id' => $order->get_id(),
                    'attendee_name' => $attendee_name,
                    'attendee_email' => $attendee_email,
                    'attendee_phone' => $attendee_phone,
                    'event_product_id' => $product_id,
                    'event_parent_id' => $parent_id,
                    'event_name' => $variation_name,
                    'registration_date' => current_time('mysql'),
                    'registration_status' => 'confirmed'
                ];
                
                \lgl_log('WpUsers: Creating event registration CCT via JetEngine API', [
                    'cct_slug' => '_ui_event_registrations',
                    'attendee_name' => $attendee_name,
                    'attendee_email' => $attendee_email,
                    'product_id' => $product_id,
                    'order_id' => $order->get_id()
                ]);
                
                // Use JetEngine CCT API to create the registration
                $item_id = jet_cct_api_update_item('_ui_event_registrations', $registration_data);
                
                if ($item_id && !is_wp_error($item_id)) {
                    \lgl_log('WpUsers: Event registration CCT created successfully', [
                        'item_id' => $item_id,
                        'attendee_name' => $attendee_name
                    ]);
                    
                    $created_registrations[] = [
                        'item_id' => $item_id,
                        'attendee' => $attendee
                    ];
                } else {
                    $error_msg = $item_id && is_wp_error($item_id) ? $item_id->get_error_message() : 'Unknown error';
                    error_log('LGL WP Users: Failed to create event registration CCT: ' . $error_msg);
                    \lgl_log('WpUsers: CCT creation failed', [
                        'error' => $error_msg,
                        'item_id' => $item_id
                    ]);
                }
            }
            
            return [
                'success' => true,
                'created_count' => count($created_registrations),
                'registrations' => $created_registrations
            ];
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error creating event registration CCT: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Create class registration CCT records
     * 
     * @param \WC_Order $order WooCommerce order
     * @param int $product_id Product ID
     * @param array $order_meta Order metadata
     * @return array Result with success status and created registrations
     */
    public function createClassRegistrationCct(\WC_Order $order, int $product_id, array $order_meta = []): array {
        try {
            // Check if JetEngine is available
            if (!function_exists('jet_cct_api_update_item')) {
                error_log('LGL WP Users: JetEngine CCT API not available');
                return ['success' => false, 'error' => 'JetEngine not available'];
            }
            
            $order_id = $order->get_id();
            $order_date = $order->get_date_created();
            
            // Get product details
            $product = wc_get_product($product_id);
            if (!$product) {
                return ['success' => false, 'error' => 'Product not found'];
            }
            
            $class_name = $product->get_name();
            $product_meta = get_post_meta($product_id);
            
            // Build CCT data
            $registration_data = [
                'user_id' => $order->get_customer_id(),
                'user_firstname' => $order->get_billing_first_name(),
                'user_lastname' => $order->get_billing_last_name(),
                'user_email' => $order->get_billing_email(),
                'user_phone' => $order->get_billing_phone(),
                'user_preferred_language' => $order_meta['languages'] ?? '',
                'user_home_country' => $order_meta['country'] ?? '',
                'class_name' => $class_name,
                'class_price' => $order->get_total(),
                'class_semester' => $product_meta['_lc_class_semester'][0] ?? '',
                'class_meeting_days' => $product_meta['_lc_class_meeting_days'][0] ?? '',
                'class_post_id' => $product_id,
                'class_order_id' => $order_id,
                'created_at' => $order_date ? $order_date->format('Y-m-d H:i:s') : current_time('mysql'),
            ];
            
            \lgl_log('WpUsers: Creating class registration CCT via JetEngine API', [
                'cct_slug' => 'class_registrations',
                'class_name' => $class_name,
                'order_id' => $order_id,
                'product_id' => $product_id,
                'user_id' => $order->get_customer_id()
            ]);
            
            // Use JetEngine CCT API to create the registration
            $item_id = jet_cct_api_update_item('class_registrations', $registration_data);
            
            if ($item_id && !is_wp_error($item_id)) {
                \lgl_log('WpUsers: Class registration CCT created successfully', [
                    'item_id' => $item_id,
                    'class_name' => $class_name
                ]);
                
                return [
                    'success' => true,
                    'item_id' => $item_id,
                    'class_name' => $class_name
                ];
            } else {
                $error_msg = $item_id && is_wp_error($item_id) ? $item_id->get_error_message() : 'Unknown error';
                error_log('LGL WP Users: Failed to create class registration CCT: ' . $error_msg);
                \lgl_log('WpUsers: Class CCT creation failed', [
                    'error' => $error_msg,
                    'item_id' => $item_id
                ]);
                
                return [
                    'success' => false,
                    'error' => $error_msg
                ];
            }
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error creating class registration CCT: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Register sync dashboard widget
     */
    public function registerSyncDashboardWidget(): void {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'lgl_user_sync_widget',
                'LGL User Synchronization',
                [$this, 'renderSyncDashboardWidget']
            );
        }
    }
    
    /**
     * Render sync dashboard widget
     */
    public function renderSyncDashboardWidget(): void {
        $stats = $this->getUserSyncStats();
        
        echo '<div class="lgl-sync-widget" style="font-family: Arial, sans-serif;">';
        echo '<h4 style="margin-top: 0; color: #0073aa;">🔄 User Synchronization Status</h4>';
        
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<tr><td><strong>Total Users with LGL ID:</strong></td><td>' . $stats['users_with_lgl_id'] . '</td></tr>';
        echo '<tr><td><strong>Users without LGL ID:</strong></td><td>' . $stats['users_without_lgl_id'] . '</td></tr>';
        echo '<tr><td><strong>Last Sync:</strong></td><td>' . ($stats['last_sync'] ?: 'Never') . '</td></tr>';
        echo '<tr><td><strong>Sync Status:</strong></td><td>' . ($stats['sync_enabled'] ? '✅ Enabled' : '❌ Disabled') . '</td></tr>';
        echo '</table>';
        
        if ($stats['users_without_lgl_id'] > 0) {
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 3px;">';
            echo '<strong>⚠️ Notice:</strong> ' . $stats['users_without_lgl_id'] . ' users need LGL synchronization.';
            echo '</div>';
        }
        
        echo '<div style="text-align: center; margin-top: 15px;">';
        echo '<button type="button" class="button button-primary" onclick="window.open(\'/wp-admin/admin.php?page=lgl-sync\')">🔧 Manage Sync</button>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Sync user with LGL
     * 
     * @param int $user_id WordPress user ID
     * @return array Sync result
     */
    private function syncUserWithLgl(int $user_id): array {
        try {
            $constituents = Constituents::getInstance();
            return $constituents->setDataAndUpdate($user_id);
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error syncing user ' . $user_id . ': ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update user meta from order
     * 
     * @param int $user_id WordPress user ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     */
    private function updateUserMetaFromOrder(int $user_id, \WC_Order $order, array $order_meta): void {
        // Update basic user info
        $user_updates = [
            'user_email' => $order->get_billing_email(),
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name()
        ];
        
        wp_update_user(array_merge(['ID' => $user_id], $user_updates));
        
        // Update user meta
        $meta_mappings = [
            'user_phone' => $order->get_billing_phone(),
            'user_company' => $order->get_billing_company(),
            'user-address-1' => $order->get_billing_address_1(),
            'user-address-2' => $order->get_billing_address_2(),
            'user-city' => $order->get_billing_city(),
            'user-state' => $order->get_billing_state(),
            'user-postal-code' => $order->get_billing_postcode(),
            'user-country-of-origin' => $order->get_billing_country(),
            'last_order_id' => $order->get_id(),
            'last_order_date' => $order->get_date_created()->date('Y-m-d H:i:s')
        ];
        
        foreach ($meta_mappings as $meta_key => $value) {
            if (!empty($value)) {
                update_user_meta($user_id, $meta_key, $value);
            }
        }
        
        // Update from additional order meta
        foreach ($order_meta as $key => $value) {
            if (strpos($key, 'user-') === 0 && !empty($value)) {
                update_user_meta($user_id, $key, $value);
            }
        }
    }
    
    /**
     * Check if user should be deleted
     * 
     * @param int $user_id WordPress user ID
     * @return bool True if user should be deleted
     */
    private function shouldDeleteUser(int $user_id): bool {
        // Check if user is marked for deletion
        if (get_user_meta($user_id, 'user_deletion_scheduled', true)) {
            return true;
        }
        
        // Check if user has been inactive for too long
        $last_login = get_user_meta($user_id, 'last_login', true);
        if ($last_login && strtotime($last_login) < strtotime('-2 years')) {
            // Check if user has any orders or important data
            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders(['customer_id' => $user_id, 'limit' => 1]);
                if (empty($orders)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Process user deletion
     * 
     * @param int $user_id WordPress user ID
     * @return array Deletion result
     */
    private function processUserDeletion(int $user_id): array {
        try {
            // Get LGL ID before deletion
            $lgl_id = get_user_meta($user_id, 'lgl_constituent_id', true);
            
            // Mark constituent as inactive in LGL instead of deleting
            if ($lgl_id) {
                $constituents = Constituents::getInstance();
                $result = $constituents->updateConstituent($lgl_id, ['status' => 'inactive']);
            }
            
            // Delete WordPress user
            $deleted = wp_delete_user($user_id);
            
            if ($deleted) {
                return [
                    'success' => true,
                    'user_id' => $user_id,
                    'lgl_id' => $lgl_id,
                    'message' => 'User deleted successfully'
                ];
            } else {
                throw new \Exception('Failed to delete WordPress user');
            }
            
        } catch (\Exception $e) {
            error_log('LGL WP Users: Error deleting user ' . $user_id . ': ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get user sync statistics
     * 
     * @return array Sync statistics
     */
    private function getUserSyncStats(): array {
        $users_with_lgl = get_users([
            'meta_key' => 'lgl_constituent_id',
            'meta_compare' => 'EXISTS',
            'count_total' => true
        ]);
        
        $total_users = count_users();
        $users_without_lgl = $total_users['total_users'] - count($users_with_lgl);
        
        // Get last sync from OperationalDataManager if available
        $last_sync_date = get_option('lgl_last_sync_date'); // Fallback
        if (function_exists('UpstateInternational\\LGL\\Core\\ServiceContainer::getInstance')) {
            try {
                $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
                $operationalData = $container->get('admin.operational_data');
                $syncStats = $operationalData->getSyncStats();
                $last_sync_date = $syncStats['last_sync_date'] ?: $last_sync_date;
            } catch (\Exception $e) {
                // Fallback to direct option read if service unavailable
            }
        }
        
        return [
            'users_with_lgl_id' => count($users_with_lgl),
            'users_without_lgl_id' => $users_without_lgl,
            'total_users' => $total_users['total_users'],
            'last_sync' => $last_sync_date,
            'sync_enabled' => !empty($this->lgl->getApiKey())
        ];
    }
    
    /**
     * Shortcode handlers
     */
    public function userSyncShortcode($atts): string {
        if (!current_user_can('manage_users')) {
            return 'Access denied.';
        }
        
        $stats = $this->getUserSyncStats();
        return 'LGL User Sync Status: ' . $stats['users_with_lgl_id'] . ' synced, ' . $stats['users_without_lgl_id'] . ' pending.';
    }
    
    public function monthlyUpdateShortcode($atts): string {
        if (!current_user_can('manage_users')) {
            return 'Access denied.';
        }
        
        return $this->runMonthlyUpdate();
    }
    
    public function userDeletionShortcode($atts): string {
        if (!current_user_can('delete_users')) {
            return 'Access denied.';
        }
        
        return $this->userDeletion();
    }
    
    /**
     * Get user manager statistics
     * 
     * @return array User manager statistics
     */
    public function getManagerStats(): array {
        return [
            'sync_stats' => $this->getUserSyncStats(),
            'cron_scheduled' => [
                'monthly_update' => wp_next_scheduled(static::MONTHLY_UPDATE_HOOK),
                'user_deletion' => wp_next_scheduled(static::USER_DELETION_HOOK)
            ],
            'api_connection' => !empty($this->lgl->getApiKey())
        ];
    }
}

// Maintain backward compatibility
if (!function_exists('lgl_wp_users')) {
    function lgl_wp_users(): WpUsers {
        return WpUsers::getInstance();
    }
}
