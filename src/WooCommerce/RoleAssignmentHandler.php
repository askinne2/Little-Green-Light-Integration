<?php
/**
 * Role Assignment Handler
 * 
 * Handles WordPress role assignments and LGL group synchronization
 * based on coupon codes applied during checkout.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\GroupMembershipManager;
use UpstateInternational\LGL\Admin\SettingsManager;
use UpstateInternational\LGL\WooCommerce\CouponRoleMeta;

class RoleAssignmentHandler {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Group Membership Manager
     * 
     * @var GroupMembershipManager
     */
    private GroupMembershipManager $groupManager;
    
    /**
     * Settings Manager
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param GroupMembershipManager $groupManager Group membership manager
     * @param SettingsManager $settingsManager Settings manager
     */
    public function __construct(
        Helper $helper,
        GroupMembershipManager $groupManager,
        SettingsManager $settingsManager
    ) {
        $this->helper = $helper;
        $this->groupManager = $groupManager;
        $this->settingsManager = $settingsManager;
    }
    
    /**
     * Process role assignments based on coupon codes
     * 
     * @param int $user_id WordPress user ID
     * @param \WC_Order $order WooCommerce order
     * @param string|null $lgl_id LGL constituent ID (optional, will fetch if not provided)
     * @return void
     */
    public function processRoleAssignments(int $user_id, \WC_Order $order, ?string $lgl_id = null): void {
        $this->helper->debug('🎫 RoleAssignmentHandler::processRoleAssignments() STARTED', [
            'order_id' => $order->get_id(),
            'user_id' => $user_id,
            'lgl_id' => $lgl_id
        ]);
        
        // Get coupon codes - try multiple methods
        $coupon_codes = $order->get_coupon_codes();
        
        // Fallback: Check order meta directly if get_coupon_codes() returns empty
        if (empty($coupon_codes)) {
            $order_meta_coupons = $order->get_meta('_coupon_codes');
            if (!empty($order_meta_coupons)) {
                $coupon_codes = is_array($order_meta_coupons) ? $order_meta_coupons : [$order_meta_coupons];
            }
        }
        
        // Also check order items for coupon line items
        if (empty($coupon_codes)) {
            foreach ($order->get_items('coupon') as $coupon_item) {
                $coupon_code = method_exists($coupon_item, 'get_code') ? $coupon_item->get_code() : $coupon_item->get_name();
                if ($coupon_code) {
                    $coupon_codes[] = $coupon_code;
                }
            }
        }
        
        // Enhanced debug logging for coupon detection
        $order_meta_coupons_debug = $order->get_meta('_coupon_codes');
        $coupon_items_debug = [];
        foreach ($order->get_items('coupon') as $coupon_item) {
            $coupon_items_debug[] = [
                'code' => method_exists($coupon_item, 'get_code') ? $coupon_item->get_code() : $coupon_item->get_name(),
                'name' => $coupon_item->get_name(),
                'type' => get_class($coupon_item)
            ];
        }
        
        $this->helper->debug('🎫 RoleAssignmentHandler: Retrieved coupon codes from order', [
            'order_id' => $order->get_id(),
            'coupon_codes' => $coupon_codes,
            'coupon_codes_count' => is_array($coupon_codes) ? count($coupon_codes) : 0,
            'order_total' => $order->get_total(),
            'order_discount_total' => $order->get_discount_total(),
            'order_discount_tax' => $order->get_discount_tax(),
            'method_used' => 'get_coupon_codes()',
            'order_meta_coupons' => $order_meta_coupons_debug,
            'coupon_items' => $coupon_items_debug,
            'order_items_count' => count($order->get_items()),
            'all_order_item_types' => array_unique(array_map(function($item) {
                return $item->get_type();
            }, $order->get_items()))
        ]);
        
        if (empty($coupon_codes)) {
            $this->helper->debug('ℹ️ RoleAssignmentHandler: No coupon codes found - EXITING early (no role assignment from coupons)', [
                'order_id' => $order->get_id(),
                'user_id' => $user_id,
                'order_discount_total' => $order->get_discount_total(),
                'note' => 'This order has no coupons, so no coupon-based role assignment will occur'
            ]);
            return;
        }
        
        $role_mappings = $this->settingsManager->get('role_group_mappings') ?? [];
        $couponRoleMeta = new CouponRoleMeta($this->helper);
        
        $this->helper->debug('🎫 RoleAssignmentHandler: Processing coupon codes', [
            'order_id' => $order->get_id(),
            'user_id' => $user_id,
            'coupon_codes' => $coupon_codes,
            'role_mappings_count' => count($role_mappings)
        ]);
        
        foreach ($coupon_codes as $coupon_code) {
            $this->helper->debug('🔍 RoleAssignmentHandler: Processing individual coupon code', [
                'coupon_code' => $coupon_code,
                'coupon_code_trimmed' => trim($coupon_code),
                'coupon_code_upper' => strtoupper(trim($coupon_code)),
                'order_id' => $order->get_id(),
                'user_id' => $user_id
            ]);
            
            // Get role assignment from coupon meta (new flexible approach)
            $role_assignment = $couponRoleMeta->getCouponRoleAssignment($coupon_code);
            
            $this->helper->debug('🔍 RoleAssignmentHandler: Coupon role assignment lookup result', [
                'coupon_code' => $coupon_code,
                'role_assignment_from_meta' => $role_assignment,
                'has_role_assignment' => !empty($role_assignment)
            ]);
            
            // Fallback to settings-based mapping (backward compatibility)
            if (!$role_assignment) {
                $coupon_mappings = $this->settingsManager->get('coupon_role_mappings') ?? [];
                $coupon_code_upper = strtoupper(trim($coupon_code));
                
                $this->helper->debug('🔍 RoleAssignmentHandler: Checking settings-based coupon mappings', [
                    'coupon_code_upper' => $coupon_code_upper,
                    'coupon_mappings_keys' => array_keys($coupon_mappings),
                    'coupon_mappings' => $coupon_mappings,
                    'is_in_mappings' => isset($coupon_mappings[$coupon_code_upper])
                ]);
                
                if (isset($coupon_mappings[$coupon_code_upper])) {
                    $target_role = $coupon_mappings[$coupon_code_upper];
                    $role_assignment = [
                        'wp_role' => $target_role,
                        'lgl_group_id' => $role_mappings[$target_role]['lgl_group_id'] ?? null,
                        'lgl_group_key' => $role_mappings[$target_role]['lgl_group_key'] ?? null,
                        'scholarship_type' => null
                    ];
                    
                    $this->helper->debug('✅ RoleAssignmentHandler: Found role from settings mapping', [
                        'coupon_code' => $coupon_code,
                        'target_role' => $target_role,
                        'role_assignment' => $role_assignment
                    ]);
                } else {
                    $this->helper->debug('⚠️ RoleAssignmentHandler: Coupon not configured in settings', [
                        'coupon_code' => $coupon_code,
                        'coupon_code_upper' => $coupon_code_upper,
                        'available_mappings' => array_keys($coupon_mappings)
                    ]);
                    continue;
                }
            }
            
            $target_role = $role_assignment['wp_role'];
            
            $this->helper->debug('✅ RoleAssignmentHandler: Processing coupon role assignment', [
                'coupon_code' => $coupon_code,
                'target_role' => $target_role,
                'lgl_group_id' => $role_assignment['lgl_group_id'] ?? null,
                'scholarship_type' => $role_assignment['scholarship_type'] ?? null
            ]);
            
            // Assign WordPress role
            $this->helper->debug('🎯 RoleAssignmentHandler: About to assign WordPress role from coupon', [
                'user_id' => $user_id,
                'target_role' => $target_role,
                'coupon_code' => $coupon_code,
                'order_id' => $order->get_id(),
                'note' => 'This role assignment is triggered by a coupon code'
            ]);
            $this->assignWordPressRole($user_id, $target_role);
            
            // Handle scholarship groups (ONLY if scholarship_type is explicitly set AND role is ui_member)
            $scholarship_type = $role_assignment['scholarship_type'] ?? null;
            
            // Safety check: Only process scholarship groups if BOTH conditions are met:
            // 1. scholarship_type is explicitly set (not empty/null)
            // 2. target_role is exactly 'ui_member' (not teacher, board, vip, etc.)
            if (!empty($scholarship_type) && $scholarship_type !== 'none' && $target_role === 'ui_member') {
                $this->helper->debug('🎓 RoleAssignmentHandler: Processing scholarship group assignment', [
                    'coupon_code' => $coupon_code,
                    'scholarship_type' => $scholarship_type,
                    'target_role' => $target_role
                ]);
                
                // Get scholarship group ID from settings
                if ($scholarship_type === 'partial') {
                    $scholarship_group_id = $this->settingsManager->get('group_id_scholarship_partial');
                    if ($scholarship_group_id) {
                        $role_assignment['lgl_group_id'] = (int) $scholarship_group_id;
                        $role_assignment['lgl_group_key'] = 'scholarship_partial';
                        $this->helper->debug('✅ RoleAssignmentHandler: Set partial scholarship group', [
                            'group_id' => $scholarship_group_id
                        ]);
                    }
                } elseif ($scholarship_type === 'full') {
                    $scholarship_group_id = $this->settingsManager->get('group_id_scholarship_full');
                    if ($scholarship_group_id) {
                        $role_assignment['lgl_group_id'] = (int) $scholarship_group_id;
                        $role_assignment['lgl_group_key'] = 'scholarship_full';
                        $this->helper->debug('✅ RoleAssignmentHandler: Set full scholarship group', [
                            'group_id' => $scholarship_group_id
                        ]);
                    }
                }
            } else {
                // Log when scholarship groups are NOT being added (for debugging)
                if ($target_role !== 'ui_member') {
                    $this->helper->debug('ℹ️ RoleAssignmentHandler: Skipping scholarship group (role is not ui_member)', [
                        'coupon_code' => $coupon_code,
                        'target_role' => $target_role,
                        'scholarship_type' => $scholarship_type
                    ]);
                } elseif (empty($scholarship_type) || $scholarship_type === 'none') {
                    $this->helper->debug('ℹ️ RoleAssignmentHandler: Skipping scholarship group (no scholarship type set)', [
                        'coupon_code' => $coupon_code,
                        'target_role' => $target_role,
                        'scholarship_type' => $scholarship_type
                    ]);
                }
            }
            
            // Sync to LGL group (if role has group mapping)
            if (!empty($role_assignment['lgl_group_id'])) {
                $group_config = [
                    'lgl_group_id' => $role_assignment['lgl_group_id'],
                    'lgl_group_key' => $role_assignment['lgl_group_key'] ?? null
                ];
                
                // Get LGL ID if not provided
                if (!$lgl_id) {
                    $lgl_id = get_user_meta($user_id, 'lgl_id', true);
                }
                
                if ($lgl_id) {
                    $this->syncToLglGroup($lgl_id, $group_config);
                } else {
                    // Store for later sync (when constituent is created)
                    $this->storePendingGroupSync($user_id, $group_config);
                }
            }
            
            // Track coupon usage in order notes
            $this->trackCouponUsage($order, $coupon_code, $role_assignment);
            
            // Handle scholarship tracking (separate note for scholarship-specific info)
            if (!empty($scholarship_type)) {
                $this->trackScholarship($order, $coupon_code, $scholarship_type);
            }
        }
    }
    
    /**
     * Assign WordPress role to user
     * 
     * @param int $user_id WordPress user ID
     * @param string $role Role slug
     * @return void
     */
    private function assignWordPressRole(int $user_id, string $role): void {
        $user = get_user_by('id', $user_id);
        if (!$user) {
            $this->helper->debug('❌ RoleAssignmentHandler: User not found', [
                'user_id' => $user_id
            ]);
            return;
        }
        
        // Don't override existing roles if user already has a higher role
        $existing_roles = $user->roles;
        
        // Add the new role (WordPress allows multiple roles)
        if (!in_array($role, $existing_roles)) {
            $user->add_role($role);
            $this->helper->debug('✅ RoleAssignmentHandler: WordPress role assigned', [
                'user_id' => $user_id,
                'role' => $role,
                'previous_roles' => $existing_roles,
                'new_roles' => $user->roles
            ]);
        } else {
            $this->helper->debug('ℹ️ RoleAssignmentHandler: User already has role', [
                'user_id' => $user_id,
                'role' => $role,
                'existing_roles' => $existing_roles
            ]);
        }
    }
    
    /**
     * Sync role to LGL group
     * 
     * @param string $lgl_id LGL constituent ID
     * @param array $group_config Group configuration from settings
     * @return void
     */
    private function syncToLglGroup(string $lgl_id, array $group_config): void {
        $group_id = $group_config['lgl_group_id'] ?? null;
        
        if (!$group_id) {
            $this->helper->debug('⚠️ RoleAssignmentHandler: No group ID in config', [
                'lgl_id' => $lgl_id,
                'group_config' => $group_config
            ]);
            return;
        }
        
        // Check if already in group
        if ($this->groupManager->isInGroup($lgl_id, (int)$group_id)) {
            $this->helper->debug('ℹ️ RoleAssignmentHandler: Already in group', [
                'lgl_id' => $lgl_id,
                'group_id' => $group_id,
                'group_name' => $group_config['lgl_group_key'] ?? 'unknown'
            ]);
            return;
        }
        
        // Add to group
        $response = $this->groupManager->addGroupMembership($lgl_id, (int)$group_id);
        
        if ($response['success']) {
            $this->helper->debug('✅ RoleAssignmentHandler: Added to LGL group', [
                'lgl_id' => $lgl_id,
                'group_id' => $group_id,
                'group_name' => $group_config['lgl_group_key'] ?? 'unknown',
                'group_membership_id' => $response['data']['id'] ?? null
            ]);
        } else {
            $this->helper->debug('❌ RoleAssignmentHandler: Failed to add to LGL group', [
                'lgl_id' => $lgl_id,
                'group_id' => $group_id,
                'group_name' => $group_config['lgl_group_key'] ?? 'unknown',
                'error' => $response['error'] ?? 'Unknown error',
                'http_code' => $response['http_code'] ?? null
            ]);
        }
    }
    
    /**
     * Store pending group sync for later (when constituent is created)
     * 
     * @param int $user_id WordPress user ID
     * @param array $group_config Group configuration
     * @return void
     */
    private function storePendingGroupSync(int $user_id, array $group_config): void {
        $pending = get_user_meta($user_id, '_lgl_pending_group_sync', true) ?: [];
        
        // Check if this group is already pending
        $already_pending = false;
        foreach ($pending as $pending_config) {
            if (isset($pending_config['lgl_group_id']) && 
                isset($group_config['lgl_group_id']) &&
                $pending_config['lgl_group_id'] === $group_config['lgl_group_id']) {
                $already_pending = true;
                break;
            }
        }
        
        if (!$already_pending) {
            $pending[] = $group_config;
            update_user_meta($user_id, '_lgl_pending_group_sync', $pending);
            
            $this->helper->debug('💾 RoleAssignmentHandler: Stored pending group sync', [
                'user_id' => $user_id,
                'group_config' => $group_config,
                'pending_count' => count($pending)
            ]);
        }
    }
    
    /**
     * Process pending group syncs (call after constituent is created)
     * 
     * @param int $user_id WordPress user ID
     * @param string $lgl_id LGL constituent ID
     * @return void
     */
    public function processPendingGroupSyncs(int $user_id, string $lgl_id): void {
        $pending = get_user_meta($user_id, '_lgl_pending_group_sync', true);
        
        if (empty($pending) || !is_array($pending)) {
            return;
        }
        
        $this->helper->debug('🔄 RoleAssignmentHandler: Processing pending group syncs', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id,
            'pending_count' => count($pending)
        ]);
        
        foreach ($pending as $group_config) {
            $this->syncToLglGroup($lgl_id, $group_config);
        }
        
        // Clear pending syncs after processing
        delete_user_meta($user_id, '_lgl_pending_group_sync');
        
        $this->helper->debug('✅ RoleAssignmentHandler: Completed pending group syncs', [
            'user_id' => $user_id,
            'lgl_id' => $lgl_id,
            'processed_count' => count($pending)
        ]);
    }
    
    /**
     * Track coupon usage in order notes
     * 
     * @param \WC_Order $order WooCommerce order
     * @param string $coupon_code Coupon code
     * @param array $role_assignment Role assignment configuration
     * @return void
     */
    private function trackCouponUsage(\WC_Order $order, string $coupon_code, array $role_assignment): void {
        $role = $role_assignment['wp_role'] ?? 'unknown';
        $group_key = $role_assignment['lgl_group_key'] ?? null;
        $group_id = $role_assignment['lgl_group_id'] ?? null;
        
        // Format role name for display
        $role_labels = [
            'ui_teacher' => 'Teacher',
            'ui_board' => 'Board Member',
            'ui_vip' => 'VIP',
            'ui_member' => 'Member'
        ];
        $role_label = $role_labels[$role] ?? ucfirst(str_replace('ui_', '', $role));
        
        // Build note with coupon and role information
        $note_parts = [
            sprintf('Coupon Applied: %s', strtoupper($coupon_code)),
            sprintf('Role Assigned: %s', $role_label)
        ];
        
        // Add LGL group information if available
        if ($group_id && $group_key) {
            $group_name = ucwords(str_replace('_', ' ', $group_key));
            $note_parts[] = sprintf('LGL Group: %s (ID: %d)', $group_name, $group_id);
        }
        
        $note = implode(' | ', $note_parts);
        
        $order->add_order_note($note);
        
        // Also store in order meta for reporting
        $order->update_meta_data('_ui_coupon_code', $coupon_code);
        $order->update_meta_data('_ui_role_assigned', $role);
        if ($group_id) {
            $order->update_meta_data('_ui_lgl_group_id', $group_id);
        }
        $order->save();
        
        $this->helper->debug('📝 RoleAssignmentHandler: Coupon usage tracked', [
            'order_id' => $order->get_id(),
            'coupon_code' => $coupon_code,
            'role' => $role_label,
            'group_id' => $group_id
        ]);
    }
    
    /**
     * Track scholarship in order notes
     * 
     * @param \WC_Order $order WooCommerce order
     * @param string $coupon_code Coupon code
     * @param string $scholarship_type Scholarship type ('partial' or 'full')
     * @return void
     */
    private function trackScholarship(\WC_Order $order, string $coupon_code, string $scholarship_type): void {
        $scholarship_label = $scholarship_type === 'partial'
            ? 'Partial Scholarship (100-200% poverty level)'
            : 'Full Scholarship (Below 100% poverty level)';
        
        $note = sprintf(
            'Scholarship Applied: %s - Coupon: %s',
            $scholarship_label,
            $coupon_code
        );
        
        $order->add_order_note($note);
        
        // Also store in order meta for reporting
        $order->update_meta_data('_ui_scholarship_type', $scholarship_label);
        $order->update_meta_data('_ui_scholarship_coupon', $coupon_code);
        $order->save();
        
        $this->helper->debug('📝 RoleAssignmentHandler: Scholarship tracked', [
            'order_id' => $order->get_id(),
            'scholarship_type' => $scholarship_label,
            'coupon_code' => $coupon_code
        ]);
    }
}

