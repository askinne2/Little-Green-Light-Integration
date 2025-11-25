<?php
/**
 * Membership User Manager
 * 
 * Handles UI member role management, status tracking, and user-specific membership operations.
 * Modernized version of UI_Memberships_WP_Users with proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * MembershipUserManager Class
 * 
 * Manages UI member roles, status, and membership-specific user operations
 */
class MembershipUserManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * WP Users service
     * 
     * @var WpUsers
     */
    private WpUsers $wpUsers;
    
    /**
     * UI Member roles
     */
    const UI_MEMBER_ROLES = ['ui_member', 'ui_patron_owner'];
    
    /**
     * Subscription statuses
     */
    const SUBSCRIPTION_STATUSES = [
        'active' => 'Active Subscription',
        'in-person' => 'In-Person Membership',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
        'pending' => 'Pending'
    ];
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param WpUsers $wpUsers WP Users service
     */
    public function __construct(Helper $helper, WpUsers $wpUsers) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
    }
    
    /**
     * Get all UI members with their membership details
     * 
     * @return array Array of member data
     */
    public function getAllUiMembers(): array {
        $users = get_users([
            'role__in' => self::UI_MEMBER_ROLES,
            'meta_key' => 'user-membership-renewal-date',
            'meta_compare' => 'EXISTS'
        ]);
        
        $members = [];
        
        foreach ($users as $user) {
            $member_data = $this->getMemberData($user->ID);
            if ($member_data) {
                $members[] = $member_data;
            }
        }
        
        return $members;
    }
    
    /**
     * Get detailed membership data for a specific user
     * 
     * @param int $user_id User ID
     * @return array|null Member data or null if not found
     */
    public function getMemberData(int $user_id): ?array {
        $user = get_userdata($user_id);
        if (!$user) {
            return null;
        }
        
        // Check if user has UI member role
        if (!array_intersect($user->roles, self::UI_MEMBER_ROLES)) {
            return null;
        }
        
        $renewal_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
        $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
        $payment_method = get_user_meta($user_id, 'payment-method', true);
        
        $member_data = [
            'user_id' => $user_id,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'first_name' => get_user_meta($user_id, 'first_name', true),
            'last_name' => get_user_meta($user_id, 'last_name', true),
            'display_name' => $user->display_name,
            'roles' => $user->roles,
            'primary_role' => $this->getPrimaryUiRole($user->roles),
            'subscription_status' => $subscription_status,
            'payment_method' => $payment_method,
            'renewal_timestamp' => $renewal_timestamp,
            'renewal_date' => null,
            'days_until_renewal' => null,
            'membership_status' => 'unknown'
        ];
        
        // Calculate renewal information
        if ($renewal_timestamp) {
            $renewal_date = new \DateTime('@' . $renewal_timestamp, new \DateTimeZone('America/New_York'));
            $today = new \DateTime();
            $interval = $today->diff($renewal_date);
            $days_until_renewal = (int) $interval->format('%r%a');
            
            $member_data['renewal_date'] = $renewal_date->format('Y-m-d');
            $member_data['days_until_renewal'] = $days_until_renewal;
            $member_data['membership_status'] = $this->calculateMembershipStatus($days_until_renewal);
        }
        
        return $member_data;
    }
    
    /**
     * Get primary UI role for a user
     * 
     * @param array $roles User roles
     * @return string Primary UI role
     */
    private function getPrimaryUiRole(array $roles): string {
        if (in_array('ui_patron_owner', $roles)) {
            return 'ui_patron_owner';
        } elseif (in_array('ui_member', $roles)) {
            return 'ui_member';
        }
        
        return 'none';
    }
    
    /**
     * Calculate membership status based on days until renewal
     * 
     * @param int $days_until_renewal Days until renewal (negative if overdue)
     * @return string Membership status
     */
    private function calculateMembershipStatus(int $days_until_renewal): string {
        if ($days_until_renewal < -30) {
            return 'expired';
        } elseif ($days_until_renewal < 0) {
            return 'overdue';
        } elseif ($days_until_renewal <= 7) {
            return 'due_soon';
        } elseif ($days_until_renewal <= 30) {
            return 'due_this_month';
        } else {
            return 'current';
        }
    }
    
    /**
     * Update user subscription status
     * 
     * @param int $user_id User ID
     * @param string $status New subscription status
     * @return bool Success status
     */
    public function updateSubscriptionStatus(int $user_id, string $status): bool {
        if (!array_key_exists($status, self::SUBSCRIPTION_STATUSES)) {
            $this->helper->error('LGL MembershipUserManager: Invalid subscription status', [
                'user_id' => $user_id,
                'status' => $status
            ]);
            return false;
        }
        
        $updated = update_user_meta($user_id, 'user-subscription-status', $status);
        
        if ($updated) {
            $this->helper->info('LGL MembershipUserManager: Updated subscription status', [
                'user_id' => $user_id,
                'status' => $status
            ]);
            
            // Log the change
            $this->logStatusChange($user_id, 'subscription_status', $status);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Update user renewal date
     * 
     * @param int $user_id User ID
     * @param string|int $renewal_date Renewal date (timestamp or date string)
     * @return bool Success status
     */
    public function updateRenewalDate(int $user_id, $renewal_date): bool {
        // Convert to timestamp if needed
        if (is_string($renewal_date)) {
            $timestamp = strtotime($renewal_date);
            if ($timestamp === false) {
                $this->helper->error('LGL MembershipUserManager: Invalid renewal date format', [
                    'user_id' => $user_id,
                    'renewal_date' => $renewal_date
                ]);
                return false;
            }
        } else {
            $timestamp = (int) $renewal_date;
        }
        
        $updated = update_user_meta($user_id, 'user-membership-renewal-date', $timestamp);
        
        if ($updated) {
            $date_formatted = date('Y-m-d', $timestamp);
            $this->helper->info('LGL MembershipUserManager: Updated renewal date', [
                'user_id' => $user_id,
                'renewal_date' => $date_formatted
            ]);
            
            // Log the change
            $this->logStatusChange($user_id, 'renewal_date', $date_formatted);
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Activate UI membership for a user
     * 
     * @param int $user_id User ID
     * @param string $membership_type Membership type (ui_member or ui_patron_owner)
     * @param string $renewal_date Renewal date
     * @return bool Success status
     */
    public function activateMembership(int $user_id, string $membership_type = 'ui_member', string $renewal_date = ''): bool {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->helper->error('LGL MembershipUserManager: User not found', [
                'user_id' => $user_id
            ]);
            return false;
        }
        
        // Validate membership type
        if (!in_array($membership_type, self::UI_MEMBER_ROLES)) {
            $this->helper->error('LGL MembershipUserManager: Invalid membership type', [
                'user_id' => $user_id,
                'membership_type' => $membership_type
            ]);
            return false;
        }
        
        try {
            // Add UI member role
            $user->add_role($membership_type);
            
            // Set subscription status
            $this->updateSubscriptionStatus($user_id, 'active');
            
            // Set renewal date (default to 1 year from now)
            if (empty($renewal_date)) {
                $renewal_date = date('Y-m-d', strtotime('+1 year'));
            }
            $this->updateRenewalDate($user_id, $renewal_date);
            
            $this->helper->info('LGL MembershipUserManager: Membership activated', [
                'user_id' => $user_id,
                'membership_type' => $membership_type
            ]);
            
            // Log the activation
            $this->logStatusChange($user_id, 'membership_activated', $membership_type);
            
            return true;
            
        } catch (\Exception $e) {
            $this->helper->error('LGL MembershipUserManager: Error activating membership', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Deactivate UI membership for a user
     * 
     * @param int $user_id User ID
     * @param string $reason Deactivation reason
     * @return bool Success status
     */
    public function deactivateMembership(int $user_id, string $reason = 'manual'): bool {
        $user = get_userdata($user_id);
        if (!$user) {
            $this->helper->error('LGL MembershipUserManager: User not found', [
                'user_id' => $user_id
            ]);
            return false;
        }
        
        try {
            // Remove UI member roles
            foreach (self::UI_MEMBER_ROLES as $role) {
                if (in_array($role, $user->roles)) {
                    $user->remove_role($role);
                }
            }
            
            // Update subscription status
            $this->updateSubscriptionStatus($user_id, 'expired');
            
            // Use WP Users service for full deactivation if needed
            if ($reason === 'membership_expired') {
                $this->wpUsers->userDeactivation($user_id, $reason);
            }
            
            $this->helper->info('LGL MembershipUserManager: Membership deactivated', [
                'user_id' => $user_id,
                'reason' => $reason
            ]);
            
            // Log the deactivation
            $this->logStatusChange($user_id, 'membership_deactivated', $reason);
            
            return true;
            
        } catch (\Exception $e) {
            $this->helper->error('LGL MembershipUserManager: Error deactivating membership', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Check subscription status in database
     * 
     * @param int $user_id User ID
     * @return string|null Subscription status or null if not found
     */
    public function checkSubscriptionStatus(int $user_id): ?string {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'ueqdu6vhs3_jet_fb_subscriptions';
        
        // Check if table exists (using prepared statement for security)
        $table_check = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        if ($table_check !== $table_name) {
            return null;
        }
        
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE user_id = %d ORDER BY id DESC LIMIT 1",
            $user_id
        ));
        
        return $result ? $result->status : null;
    }
    
    /**
     * Get membership statistics
     * 
     * @return array Statistics about memberships
     */
    public function getMembershipStatistics(): array {
        $members = $this->getAllUiMembers();
        
        $stats = [
            'total_members' => count($members),
            'by_status' => [
                'current' => 0,
                'due_this_month' => 0,
                'due_soon' => 0,
                'overdue' => 0,
                'expired' => 0
            ],
            'by_role' => [
                'ui_member' => 0,
                'ui_patron_owner' => 0
            ],
            'by_subscription' => []
        ];
        
        foreach ($members as $member) {
            // Count by status
            $status = $member['membership_status'];
            if (isset($stats['by_status'][$status])) {
                $stats['by_status'][$status]++;
            }
            
            // Count by role
            $role = $member['primary_role'];
            if (isset($stats['by_role'][$role])) {
                $stats['by_role'][$role]++;
            }
            
            // Count by subscription type
            $subscription = $member['subscription_status'] ?: 'unknown';
            if (!isset($stats['by_subscription'][$subscription])) {
                $stats['by_subscription'][$subscription] = 0;
            }
            $stats['by_subscription'][$subscription]++;
        }
        
        return $stats;
    }
    
    /**
     * Log status change for audit trail
     * 
     * @param int $user_id User ID
     * @param string $change_type Type of change
     * @param string $new_value New value
     * @return void
     */
    private function logStatusChange(int $user_id, string $change_type, string $new_value): void {
        $log_entry = [
            'timestamp' => current_time('timestamp'),
            'user_id' => $user_id,
            'change_type' => $change_type,
            'new_value' => $new_value,
            'admin_user' => get_current_user_id()
        ];
        
        // Get existing logs
        $logs = get_option('ui_membership_status_logs', []);
        
        // Add new log entry
        $logs[] = $log_entry;
        
        // Keep only last 1000 entries
        if (count($logs) > 1000) {
            $logs = array_slice($logs, -1000);
        }
        
        // Save logs
        update_option('ui_membership_status_logs', $logs);
    }
    
    /**
     * Get status change logs for a user
     * 
     * @param int $user_id User ID
     * @param int $limit Number of logs to return
     * @return array Log entries
     */
    public function getUserStatusLogs(int $user_id, int $limit = 50): array {
        $all_logs = get_option('ui_membership_status_logs', []);
        
        // Filter logs for this user
        $user_logs = array_filter($all_logs, function($log) use ($user_id) {
            return isset($log['user_id']) && $log['user_id'] == $user_id;
        });
        
        // Sort by timestamp (newest first)
        usort($user_logs, function($a, $b) {
            return $b['timestamp'] - $a['timestamp'];
        });
        
        // Limit results
        return array_slice($user_logs, 0, $limit);
    }
    
    /**
     * Get available subscription statuses
     * 
     * @return array Subscription statuses
     */
    public function getSubscriptionStatuses(): array {
        return self::SUBSCRIPTION_STATUSES;
    }
    
    /**
     * Get UI member roles
     * 
     * @return array UI member roles
     */
    public function getUiMemberRoles(): array {
        return self::UI_MEMBER_ROLES;
    }
    
    /**
     * Get service status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        $stats = $this->getMembershipStatistics();
        
        return [
            'total_ui_members' => $stats['total_members'],
            'available_roles' => self::UI_MEMBER_ROLES,
            'available_statuses' => self::SUBSCRIPTION_STATUSES,
            'statistics' => $stats,
            'wp_users_service' => class_exists(get_class($this->wpUsers))
        ];
    }
}
