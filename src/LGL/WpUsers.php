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
        
        // Register shortcodes
        $this->registerShortcodes();
        
        // Register dashboard widgets
        // add_action('wp_dashboard_setup', [$this, 'registerSyncDashboardWidget']);
        
        // Helper::getInstance()->debug('LGL WP Users: Initialized successfully');
    }
    
    /**
     * Register shortcodes
     * 
     * @return void
     */
    private function registerShortcodes(): void {
        add_shortcode('lgl_check_memberships', [$this, 'handleCheckMembershipsShortcode']);
    }
    
    /**
     * Handle [lgl_check_memberships] shortcode
     * 
     * Legacy shortcode for checking and deleting inactive members.
     * 
     * @param array $atts Shortcode attributes
     * @return string Output (empty for this shortcode)
     */
    public function handleCheckMembershipsShortcode(array $atts = []): string {
        // This shortcode triggers user deletion check
        // It's kept for backward compatibility but functionality is handled via cron
        // Optionally trigger manual check if requested
        if (isset($atts['trigger']) && $atts['trigger'] === 'true') {
            if (current_user_can('manage_options')) {
                $this->runMonthlyUserCleanup();
            }
        }
        
        return ''; // No output for this shortcode
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
        // DISABLED: Monthly bulk sync - users sync automatically on orders/profile updates
        // This was consuming ~900+ API calls and causing rate limit issues
        // Users are already synced when:
        // - Orders are placed
        // - Users register
        // - Profiles are updated
        // - Memberships change
        /*
        // Schedule monthly update if not already scheduled
        if (!wp_next_scheduled(static::MONTHLY_UPDATE_HOOK)) {
            wp_schedule_event(time(), 'monthly', static::MONTHLY_UPDATE_HOOK);
        }
        
        // Register cron actions
        add_action(static::MONTHLY_UPDATE_HOOK, [$this, 'runMonthlyUpdate']);
        */
        
        // Schedule user deletion check if not already scheduled
        if (!wp_next_scheduled(static::USER_DELETION_HOOK)) {
            wp_schedule_event(time(), 'daily', static::USER_DELETION_HOOK);
        }
        
        // Register cron actions
        add_action(static::USER_DELETION_HOOK, [$this, 'userDeletion']);
        
        // Register legacy monthly cleanup hook (matches legacy LGL_API::UI_DELETE_MEMBERS)
        add_action('ui_members_monthly_hook', [$this, 'runMonthlyUserCleanup']);
    }
    
    /**
     * Run monthly user cleanup (legacy compatibility)
     * 
     * Matches legacy LGL_WP_Users::run_monthly_update() behavior.
     * Runs on the 4th day of even-numbered months.
     * 
     * @return void
     */
    public function runMonthlyUserCleanup(): void {
        // Check if it's the 4th day of an even-numbered month (matches legacy logic)
        if (date('j') === '4' && date('n') % 2 === 0) {
            // Check if deletion is enabled (matches legacy DELETE_MEMBERS constant)
            $delete_members = defined('DELETE_MEMBERS') ? DELETE_MEMBERS : false;
            
            if ($delete_members) {
                $this->deleteInactiveUsers();
            }
        }
    }
    
    /**
     * Delete inactive users (matches legacy user_deletion behavior)
     * 
     * @return void
     */
    private function deleteInactiveUsers(): void {
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        
        $blogusers = get_users([
            'role__in' => ['ui_inactive_member']
        ]);
        
        if (!$blogusers) {
            return;
        }
        
        foreach ($blogusers as $user) {
            $user_id = $user->ID;
            
            // Handle family member deletion first
            $this->handleFamilyMemberDeletion($user_id);
            
            // Get user posts
            $user_posts = get_posts([
                'author' => $user_id,
                'posts_per_page' => -1
            ]);
            
            // Delete each post
            foreach ($user_posts as $post) {
                wp_delete_post($post->ID, true); // Bypass trash
            }
            
            // Delete the user
            wp_delete_user($user_id);
        }
    }
    
    /**
     * Handle family member deletion (matches legacy family_member_deletion behavior)
     * 
     * @param int $user_id WordPress user ID
     * @return void
     */
    private function handleFamilyMemberDeletion(int $user_id): void {
        // Get user role
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        
        $current_role = $user->roles[0] ?? '';
        
        // If the user is 'ui_patron_owner', handle child deactivation
        if ($current_role === 'ui_patron_owner') {
            // Get child relations (this would need RelationsManager)
            // TODO: Implement child relation handling via RelationsManager
            // This would deactivate child users before deleting the parent
        }
    }
    
    /**
     * Run monthly user update
     * 
     * @return string Status message
     */
    public function runMonthlyUpdate(): string {
        try {
            // Get users with LGL ID (canonical field: lgl_id, with legacy fallback)
            $users = get_users([
                'meta_query' => [
                    'relation' => 'OR',
                    [
                        'key' => 'lgl_id',
                        'compare' => 'EXISTS'
                    ],
                    [
                        'key' => 'lgl_constituent_id',
                        'compare' => 'EXISTS'
                    ]
                ]
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
            
            Helper::getInstance()->info('LGL WP Users: Monthly update completed', [
                'updated_count' => $updated_count,
                'error_count' => count($errors)
            ]);
            return $message;
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Monthly update failed', [
                'error' => $e->getMessage()
            ]);
            return 'Monthly update failed: ' . $e->getMessage();
        }
    }
    
    /**
     * Migrate legacy LGL ID meta fields to canonical lgl_id
     * 
     * Copies lgl_constituent_id and lgl_user_id to lgl_id for users missing the canonical field.
     * This is a one-time migration that can be run manually or via WP-CLI.
     * 
     * @param bool $dry_run If true, only reports what would be migrated without making changes
     * @return array Migration results
     */
    public function migrateLglIdMetaFields(bool $dry_run = false): array {
        $helper = Helper::getInstance();
        $results = [
            'processed' => 0,
            'migrated' => 0,
            'skipped' => 0,
            'errors' => [],
            'details' => []
        ];
        
        // Get all users with legacy fields but missing canonical field
        $users = get_users([
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'lgl_id',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => 'lgl_constituent_id',
                        'compare' => 'EXISTS'
                    ],
                    [
                        'key' => 'lgl_user_id',
                        'compare' => 'EXISTS'
                    ]
                ]
            ],
            'number' => -1 // Get all users
        ]);
        
        foreach ($users as $user) {
            $results['processed']++;
            
            try {
                // Check legacy fields in priority order
                $legacy_id = null;
                $legacy_field = null;
                
                $lgl_constituent_id = get_user_meta($user->ID, 'lgl_constituent_id', true);
                if (!empty($lgl_constituent_id)) {
                    $legacy_id = $lgl_constituent_id;
                    $legacy_field = 'lgl_constituent_id';
                } else {
                    $lgl_user_id = get_user_meta($user->ID, 'lgl_user_id', true);
                    if (!empty($lgl_user_id)) {
                        $legacy_id = $lgl_user_id;
                        $legacy_field = 'lgl_user_id';
                    }
                }
                
                if (!$legacy_id) {
                    $results['skipped']++;
                    continue;
                }
                
                if (!$dry_run) {
                    // Migrate to canonical field
                    update_user_meta($user->ID, 'lgl_id', $legacy_id);
                    $results['migrated']++;
                    $results['details'][] = "User {$user->ID}: Migrated {$legacy_field} ({$legacy_id}) → lgl_id";
                } else {
                    $results['details'][] = "User {$user->ID}: Would migrate {$legacy_field} ({$legacy_id}) → lgl_id";
                }
                
            } catch (\Exception $e) {
                $results['errors'][] = [
                    'user_id' => $user->ID,
                    'error' => $e->getMessage()
                ];
                $helper->error('LGL WP Users: Migration error for user', [
                    'user_id' => $user->ID,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $helper->info('LGL WP Users: LGL ID migration completed', [
            'processed' => $results['processed'],
            'migrated' => $results['migrated'],
            'skipped' => $results['skipped'],
            'errors' => count($results['errors'])
        ]);
        return $results;
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
            Helper::getInstance()->info('LGL WP Users: User deletion check completed', [
                'processed' => $processed_count,
                'deleted' => $deleted_count
            ]);
            return $message;
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: User deletion failed', [
                'error' => $e->getMessage()
            ]);
            return 'User deletion failed: ' . $e->getMessage();
        }
    }
    
    /**
     * Deactivate WordPress user (matches legacy ui_deactivate_user behavior)
     * 
     * Changes user role to ui_inactive_member and resets password.
     * Used when membership is cancelled or expired.
     * 
     * @param int $user_id WordPress user ID
     * @param string|null $reason Optional deactivation reason
     * @return void
     */
    public function uiUserDeactivation(int $user_id, ?string $reason = null): void {
        $user = get_userdata($user_id);
        if (!$user) {
            Helper::getInstance()->error('LGL WP Users: User not found for deactivation', [
                'user_id' => $user_id
            ]);
            return;
        }
        
        try {
            // Add inactive member role
            $user->add_role('ui_inactive_member');
            
            // Reset password to random string (matches legacy behavior)
            $random_password = wp_generate_password(12, true, true);
            wp_set_password($random_password, $user_id);
            
            Helper::getInstance()->info('LGL WP Users: User deactivated', [
                'user_id' => $user_id,
                'reason' => $reason ?? 'manual',
                'previous_roles' => $user->roles
            ]);
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error deactivating user', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Get user role (matches legacy ui_get_user_role behavior)
     * 
     * Returns the primary UI membership role for a user.
     * Priority: ui_patron_owner > ui_member > empty string
     * 
     * @param int $user_id WordPress user ID
     * @return string User role ('ui_patron_owner', 'ui_member', or '')
     */
    public function uiGetUserRole(int $user_id): string {
        $user = get_userdata($user_id);
        if (!$user) {
            Helper::getInstance()->error('LGL WP Users: User not found', [
                'user_id' => $user_id
            ]);
            return '';
        }
        
        $user_roles = $user->roles;
        
        // Priority: ui_patron_owner > ui_member
        if (in_array('ui_patron_owner', $user_roles)) {
            return 'ui_patron_owner';
        } elseif (in_array('ui_member', $user_roles)) {
            return 'ui_member';
        }
        
        return '';
    }
    
    /**
     * Get child relations for a parent user (matches legacy ui_get_child_relations behavior)
     * 
     * Uses JetEngine relation 24 to get family member relationships.
     * 
     * @param int $parent_id Parent user ID
     * @return array Array of child user IDs
     */
    public function uiGetChildRelations(int $parent_id): array {
        if (!function_exists('jet_engine')) {
            Helper::getInstance()->debug('LGL WP Users: JetEngine not available, cannot get child relations', [
                'parent_id' => $parent_id
            ]);
            return [];
        }
        
        try {
            $relation = \jet_engine()->relations->get_active_relations(24);
            if (!$relation) {
                Helper::getInstance()->debug('LGL WP Users: Could not get JetEngine relation 24', [
                    'parent_id' => $parent_id
                ]);
                return [];
            }
            
            $related_ids = $relation->get_children($parent_id, 'ids');
            
            // Ensure we return an array
            if (!is_array($related_ids)) {
                return [];
            }
            
            return $related_ids;
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error getting child relations', [
                'parent_id' => $parent_id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }
    
    /**
     * Update user data from order
     * 
     * @param array $request Request data
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     * @param bool $skip_membership_sync Skip membership sync (for non-membership orders)
     * @param bool $skip_lgl_sync_completely Skip all LGL sync (for immediate processing - LGL sync happens async)
     * @return array Update result
     */
    public function updateUserData(
        array $request, 
        \WC_Order $order, 
        array $order_meta, 
        bool $skip_membership_sync = false,
        bool $skip_lgl_sync_completely = false
    ): array {
        try {
            $user_id = $order->get_customer_id();
            if (!$user_id) {
                throw new \Exception('No customer ID found for order');
            }
            
            // Update user meta from order (billing/contact info always syncs)
            $this->updateUserMetaFromOrder($user_id, $order, $order_meta);
            
            // Update subscription info if applicable
            $this->updateUserSubscriptionInfo($user_id, $order->get_id());
            
            // Only sync with LGL if not skipping completely (for immediate processing)
            // When skip_lgl_sync_completely=true, LGL sync happens separately in async processing
            $sync_result = null;
            if (!$skip_lgl_sync_completely) {
                $sync_result = $this->syncUserWithLgl($user_id, $skip_membership_sync);
            }
            
            return [
                'success' => true,
                'user_id' => $user_id,
                'lgl_sync' => $sync_result,
                'message' => 'User data updated successfully'
            ];
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error updating user data', [
                'error' => $e->getMessage()
            ]);
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
            Helper::getInstance()->error('LGL WP Users: Error updating subscription info', [
                'error' => $e->getMessage()
            ]);
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
                
                // Get product/order item object
                $product_item = $attendee['product'] ?? null;
                $product_obj = null;
                
                // Check if it's an order item (WC_Order_Item_Product) or product (WC_Product)
                if ($product_item) {
                    // Check if it's an order item by checking for order item methods
                    if (method_exists($product_item, 'get_total')) {
                        // It's a WC_Order_Item_Product - get the actual product object
                        $item_product_id = $product_item->get_variation_id() ?: $product_item->get_product_id();
                        $product_obj = \wc_get_product($item_product_id);
                    } else {
                        // It's already a WC_Product
                        $product_obj = $product_item;
                    }
                } elseif ($product_id) {
                    // No product object provided, get it from product_id
                    $product_obj = \wc_get_product($product_id);
                }
                
                // Get event name from product
                $event_name = '';
                if ($product_obj) {
                    $event_name = $product_obj->get_name();
                } elseif ($product_item && method_exists($product_item, 'get_name')) {
                    // Fallback to order item name
                    $event_name = $product_item->get_name();
                }
                
                // Get event datetime from parent product meta
                $event_datetime = '';
                if ($parent_id) {
                    $event_datetime = \get_post_meta($parent_id, '_ui_event_start_datetime', true);
                    // Convert timestamp to datetime-local format if needed
                    if (!empty($event_datetime) && is_numeric($event_datetime)) {
                        $event_datetime = date('Y-m-d\TH:i', $event_datetime);
                    }
                }
                
                // Get event price from order item, variation, or product
                $event_price = '';
                if ($product_item && method_exists($product_item, 'get_total')) {
                    // It's an order item - use get_total() for the line item total
                    $event_price = $product_item->get_total();
                } elseif ($product_id && function_exists('get_variation_price')) {
                    // Use helper function for variation price
                    $event_price = \get_variation_price($product_id);
                } elseif ($product_obj) {
                    // Use product object price
                    $event_price = $product_obj->get_price();
                }
                
                // Get order date for created_at
                $order_date = $order->get_date_created();
                $created_at = $order_date ? $order_date->format('Y-m-d H:i:s') : current_time('mysql');
                
                // JetEngine CCT data mapped to correct field names
                $registration_data = [
                    'user_id' => $order->get_customer_id(),
                    'user_name' => $attendee_name,
                    'user_email' => $attendee_email,
                    'user_phone' => $order->get_billing_phone() ?: $attendee_phone,
                    'event_name' => $event_name,
                    'event_option' => $variation_name,
                    'event_datetime' => $event_datetime,
                    'event_price' => $event_price,
                    'event_associated_order' => $order->get_id(),
                ];
                
                Helper::getInstance()->debug('LGL WP Users: Creating event registration CCT', [
                    'cct_slug' => '_ui_event_registrations',
                    'user_name' => $attendee_name,
                    'order_id' => $order->get_id()
                ]);
                
                // Use JetEngine CCT API to create the registration
                $item_id = jet_cct_api_update_item('_ui_event_registrations', $registration_data);
                
                if ($item_id && !is_wp_error($item_id)) {
                    Helper::getInstance()->info('LGL WP Users: Event registration CCT created', [
                        'item_id' => $item_id,
                        'attendee_name' => $attendee_name,
                        'order_id' => $order->get_id()
                    ]);
                    
                    $created_registrations[] = [
                        'item_id' => $item_id,
                        'attendee' => $attendee
                    ];
                } else {
                    $error_msg = $item_id && is_wp_error($item_id) ? $item_id->get_error_message() : 'Unknown error';
                    Helper::getInstance()->error('LGL WP Users: Failed to create event registration CCT', [
                        'error' => $error_msg
                    ]);
                }
            }
            
            return [
                'success' => true,
                'created_count' => count($created_registrations),
                'registrations' => $created_registrations
            ];
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error creating event registration CCT', [
                'error' => $e->getMessage()
            ]);
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
                Helper::getInstance()->warning('LGL WP Users: JetEngine CCT API not available');
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
            
            Helper::getInstance()->debug('LGL WP Users: Creating class registration CCT', [
                'cct_slug' => 'class_registrations',
                'class_name' => $class_name,
                'order_id' => $order_id
            ]);
            
            // Use JetEngine CCT API to create the registration
            $item_id = jet_cct_api_update_item('class_registrations', $registration_data);
            
            if ($item_id && !is_wp_error($item_id)) {
                Helper::getInstance()->info('LGL WP Users: Class registration CCT created', [
                    'item_id' => $item_id,
                    'class_name' => $class_name,
                    'order_id' => $order_id
                ]);
                
                return [
                    'success' => true,
                    'item_id' => $item_id,
                    'class_name' => $class_name
                ];
            } else {
                $error_msg = $item_id && is_wp_error($item_id) ? $item_id->get_error_message() : 'Unknown error';
                Helper::getInstance()->error('LGL WP Users: Failed to create class registration CCT', [
                    'error' => $error_msg
                ]);
                
                return [
                    'success' => false,
                    'error' => $error_msg
                ];
            }
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error creating class registration CCT', [
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Sync orders to CCTs (Event and Class Registrations)
     * 
     * Syncs completed WooCommerce orders to JetEngine CCTs for events and language classes.
     * Skips orders that have already been synced.
     * 
     * @param string|null $date_from Start date (Y-m-d format) or null for default
     * @param string|null $date_to End date (Y-m-d format) or null for current date
     * @return string Sync result message
     */
    public function syncOrdersToCcts(?string $date_from = null, ?string $date_to = null): string {
        if (!function_exists('wc_get_orders')) {
            return 'WooCommerce is required for order syncing.';
        }
        
        // Set default date range if not provided
        $date_from = $date_from ?: '2024-12-17'; // Last known sync date
        $date_to = $date_to ?: date('Y-m-d'); // Current date
        
        // Query WooCommerce orders
        $args = [
            'status' => 'completed', // Only completed orders
            'date_created' => $date_from . '...' . $date_to,
            'limit' => -1 // Retrieve all orders in the range
        ];
        
        $orders = \wc_get_orders($args);
        
        if (empty($orders)) {
            return 'No orders found to sync.';
        }
        
        $event_orders_synced = [];
        $language_orders_synced = [];
        $skipped_orders = [];
        
        foreach ($orders as $order) {
            // Skip refunds
            if (is_a($order, 'WC_Order_Refund')) {
                continue;
            }
            
            $order_id = $order->get_id();
            
            // Check if a CCT for this order_id already exists
            if (function_exists('jet_cct_api_query')) {
                $existing_event_ccts = \jet_cct_api_query('_ui_event_registrations', [
                    [
                        'field' => 'event_associated_order',
                        'operator' => '=',
                        'value' => $order_id,
                    ],
                ]);
                
                if (!empty($existing_event_ccts)) {
                    $skipped_orders[] = $order_id;
                    continue; // Skip this order
                }
            }
            
            // Check if order has already been synced (additional layer of safety)
            if ($order->get_meta('_cct_synced')) {
                $skipped_orders[] = $order_id;
                continue; // Skip already-synced orders
            }
            
            $attendees = [];
            $attendee_index = 0;
            $has_events = false;
            $has_classes = false;
            
            $uid = $order->get_customer_id();
            $order_meta = [
                'languages' => $order->get_meta('_order_languages_spoken'),
                'country' => $order->get_meta('_order_country_of_origin'),
                'referral' => $order->get_meta('_order_referral_source'),
                'reason' => $order->get_meta('_order_reason_for_membership'),
                'about' => $order->get_meta('_order_tell_us_about_yourself'),
            ];
            
            // Iterate through each product in the order
            foreach ($order->get_items() as $product_item) {
                $product_name = $product_item->get_name();
                $quantity = $product_item->get_quantity();
                $product_id = $product_item->get_variation_id() ?: $product_item->get_product_id();
                $parent_id = $product_item->get_variation_id() ? $product_item->get_product_id() : $product_id;
                
                if (has_term('language-class', 'product_cat', $parent_id)) {
                    // Sync Language Class Registration
                    $has_classes = true;
                    $cct_result = $this->createClassRegistrationCct($order, $product_id, $order_meta);
                    
                    if ($cct_result['success']) {
                        $language_orders_synced[] = $order_id;
                    }
                    
                } elseif (has_term('events', 'product_cat', $parent_id)) {
                    // Sync Event Registration
                    $has_events = true;
                    
                    // Collect attendees for this event
                    for ($j = 0; $j < $quantity; $j++) {
                        $suffix = $attendee_index === 0 ? '' : '_' . $attendee_index;
                        $attendee_name = $order->get_meta('attendee_name' . $suffix);
                        $attendee_email = $order->get_meta('attendee_email' . $suffix);
                        
                        if (empty($attendee_name) || empty($attendee_email)) {
                            // Use order billing info if attendee info not found
                            $attendee_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                            $attendee_email = $order->get_billing_email();
                        }
                        
                        if (!empty($attendee_name) && !empty($attendee_email)) {
                            $attendee = [
                                'attendee_name' => $attendee_name,
                                'attendee_email' => $attendee_email,
                                'attendee_phone' => $order->get_billing_phone(),
                                'product' => $product_item,
                                'product_id' => $product_id,
                                'parent_id' => $parent_id,
                                'variation_name' => function_exists('get_variation_name') ? get_variation_name($product_id) : '',
                            ];
                            $attendees[] = $attendee;
                            $attendee_index++;
                        }
                    }
                }
            }
            
            // Create event registration CCTs if we have attendees
            if ($has_events && !empty($attendees)) {
                $cct_result = $this->createEventRegistrationCct($order, $attendees);
                
                if ($cct_result['success']) {
                    $event_orders_synced[] = $order_id;
                }
            }
            
            // Mark the order as synced if we processed events or classes
            if ($has_events || $has_classes) {
                $order->update_meta_data('_cct_synced', true);
                $order->save();
            }
        }
        
        // Build result message
        $output = [];
        if (!empty($event_orders_synced)) {
            $output[] = count($event_orders_synced) . ' event order(s) synced successfully';
        }
        if (!empty($language_orders_synced)) {
            $output[] = count($language_orders_synced) . ' language class order(s) synced successfully';
        }
        if (!empty($skipped_orders)) {
            $output[] = count($skipped_orders) . ' order(s) skipped (already synced)';
        }
        if (empty($output)) {
            $output[] = 'No orders needed syncing in the selected date range';
        }
        
        return implode('. ', $output) . '.';
    }
    
    /**
     * Reset CCT sync status for all orders
     * 
     * Removes the _cct_synced meta flag from all orders, allowing them to be re-synced.
     * 
     * @return string Result message
     */
    public function resetCctSyncStatus(): string {
        if (!function_exists('wc_get_orders')) {
            return 'WooCommerce is required.';
        }
        
        $args = [
            'limit' => -1, // Retrieve all orders
        ];
        
        $orders = \wc_get_orders($args);
        
        if (empty($orders)) {
            return 'No orders found to reset.';
        }
        
        $reset_count = 0;
        foreach ($orders as $order) {
            // Reset the '_cct_synced' meta field
            $order->update_meta_data('_cct_synced', false);
            $order->save();
            $reset_count++;
        }
        
        return $reset_count . ' order(s) reset successfully. They can now be re-synced.';
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
    private function syncUserWithLgl(int $user_id, bool $skip_membership = false): array {
        try {
            $constituents = Constituents::getInstance();
            return $constituents->setDataAndUpdate($user_id, [], $skip_membership);
            
        } catch (\Exception $e) {
            Helper::getInstance()->error('LGL WP Users: Error syncing user', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Update user meta from order
     * 
     * Matches legacy LGL_WP_Users::update_user_data() behavior exactly.
     * 
     * @param int $user_id WordPress user ID
     * @param \WC_Order $order WooCommerce order
     * @param array $order_meta Order metadata
     */
    private function updateUserMetaFromOrder(int $user_id, \WC_Order $order, array $order_meta): void {
        // Update billing/contact info from order (matches legacy exactly)
        update_user_meta($user_id, 'user-phone', $order->get_billing_phone());
        update_user_meta($user_id, 'user-company', $order->get_billing_company());
        update_user_meta($user_id, 'user-address-1', $order->get_billing_address_1());
        update_user_meta($user_id, 'user-address-2', $order->get_billing_address_2());
        update_user_meta($user_id, 'user-city', $order->get_billing_city());
        update_user_meta($user_id, 'user-state', $order->get_billing_state());
        update_user_meta($user_id, 'user-postal-code', $order->get_billing_postcode());
        
        // Update order-specific meta fields (matches legacy exactly)
        update_user_meta($user_id, 'user-languages', isset($order_meta['languages']) ? $order_meta['languages'] : '');
        update_user_meta($user_id, 'user-country-of-origin', isset($order_meta['country']) ? $order_meta['country'] : '');
        update_user_meta($user_id, 'user-referral', isset($order_meta['referral']) ? $order_meta['referral'] : '');
        update_user_meta($user_id, 'user-reason-for-membership', isset($order_meta['reason']) ? $order_meta['reason'] : '');
        update_user_meta($user_id, 'about-me', isset($order_meta['about']) ? $order_meta['about'] : '');
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
            // Get LGL ID before deletion (canonical field: lgl_id)
            $lgl_id = get_user_meta($user_id, 'lgl_id', true);
            
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
            Helper::getInstance()->error('LGL WP Users: Error deleting user', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
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

// Register WP-CLI command for LGL ID migration
if (defined('WP_CLI') && WP_CLI) {
    /**
     * Migrate legacy LGL ID meta fields to canonical lgl_id
     * 
     * ## OPTIONS
     * 
     * [--dry-run]
     * : Preview changes without saving to database.
     * 
     * ## EXAMPLES
     * 
     *     # Preview migration (dry run)
     *     wp lgl migrate-lgl-id --dry-run
     * 
     *     # Run actual migration
     *     wp lgl migrate-lgl-id
     * 
     * @param array $args Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    \WP_CLI::add_command('lgl migrate-lgl-id', function($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        
        \WP_CLI::log('Starting LGL ID meta field migration...');
        \WP_CLI::log($dry_run ? 'Mode: DRY RUN (no changes will be made)' : 'Mode: LIVE RUN (changes will be saved)');
        \WP_CLI::log('');
        
        // Debug: Check how many users have each field
        $total_users = count_users();
        $users_with_lgl_id = get_users(['meta_key' => 'lgl_id', 'meta_compare' => 'EXISTS', 'number' => -1, 'count_total' => false]);
        $users_with_constituent_id = get_users(['meta_key' => 'lgl_constituent_id', 'meta_compare' => 'EXISTS', 'number' => -1, 'count_total' => false]);
        $users_with_user_id = get_users(['meta_key' => 'lgl_user_id', 'meta_compare' => 'EXISTS', 'number' => -1, 'count_total' => false]);
        
        \WP_CLI::log(sprintf('Total users: %d', $total_users['total_users']));
        \WP_CLI::log(sprintf('Users with lgl_id: %d', count($users_with_lgl_id)));
        \WP_CLI::log(sprintf('Users with lgl_constituent_id: %d', count($users_with_constituent_id)));
        \WP_CLI::log(sprintf('Users with lgl_user_id: %d', count($users_with_user_id)));
        \WP_CLI::log('');
        
        $wpUsers = WpUsers::getInstance();
        $results = $wpUsers->migrateLglIdMetaFields($dry_run);
        
        \WP_CLI::log(sprintf('Processed: %d users', $results['processed']));
        \WP_CLI::log(sprintf('Migrated: %d users', $results['migrated']));
        \WP_CLI::log(sprintf('Skipped: %d users', $results['skipped']));
        
        if (!empty($results['errors'])) {
            \WP_CLI::warning(sprintf('Errors: %d', count($results['errors'])));
            foreach ($results['errors'] as $error) {
                \WP_CLI::warning(sprintf('  User %d: %s', $error['user_id'], $error['error']));
            }
        }
        
        if (!empty($results['details']) && count($results['details']) <= 50) {
            \WP_CLI::log('');
            \WP_CLI::log('Migration details:');
            foreach ($results['details'] as $detail) {
                \WP_CLI::log('  ' . $detail);
            }
        } elseif (!empty($results['details'])) {
            \WP_CLI::log('');
            \WP_CLI::log(sprintf('Showing first 50 of %d migration details:', count($results['details'])));
            foreach (array_slice($results['details'], 0, 50) as $detail) {
                \WP_CLI::log('  ' . $detail);
            }
            \WP_CLI::log(sprintf('  ... and %d more', count($results['details']) - 50));
        }
        
        \WP_CLI::log('');
        if ($dry_run) {
            \WP_CLI::success('Dry run completed. Run without --dry-run to perform actual migration.');
        } else {
            \WP_CLI::success('Migration completed successfully!');
        }
    });
}
