<?php
/**
 * Membership Renewal Manager
 * 
 * Handles membership renewal date checking, status validation, and renewal notifications.
 * Modernized version of the legacy UI_Memberships system with proper architecture.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\WpUsers;

/**
 * MembershipRenewalManager Class
 * 
 * Manages membership renewal dates, status checking, and notification scheduling
 */
class MembershipRenewalManager {
    
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
     * Notification mailer
     * 
     * @var MembershipNotificationMailer
     */
    private MembershipNotificationMailer $mailer;
    
    /**
     * Renewal strategy manager
     * 
     * @var RenewalStrategyManager|null
     */
    private ?RenewalStrategyManager $strategyManager = null;
    
    /**
     * Grace period in days after renewal date
     */
    const GRACE_PERIOD_DAYS = 30;
    
    /**
     * Notification intervals in days before renewal
     */
    const NOTIFICATION_INTERVALS = [30, 14, 7, 0, -7, -30];
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param WpUsers $wpUsers WP Users service
     * @param MembershipNotificationMailer $mailer Notification mailer
     * @param RenewalStrategyManager|null $strategyManager Renewal strategy manager (optional)
     */
    public function __construct(
        Helper $helper,
        WpUsers $wpUsers,
        MembershipNotificationMailer $mailer,
        ?RenewalStrategyManager $strategyManager = null
    ) {
        $this->helper = $helper;
        $this->wpUsers = $wpUsers;
        $this->mailer = $mailer;
        $this->strategyManager = $strategyManager;
    }
    
    /**
     * Process all UI members for renewal notifications
     * 
     * @return array Processing results
     */
    public function processAllMembers(): array {
        $this->helper->info('LGL MembershipRenewalManager: Starting membership renewal processing');
        
        // Memory check before processing
        $memory_limit = $this->getMemoryLimitBytes();
        $current_memory = memory_get_usage(true);
        $memory_threshold = $memory_limit * 0.75; // Use 75% threshold
        
        if ($current_memory > $memory_threshold) {
            $this->helper->warning('LGL MembershipRenewalManager: High memory usage before processing', [
                'current_mb' => round($current_memory / 1024 / 1024, 2),
                'limit_mb' => round($memory_limit / 1024 / 1024, 2)
            ]);
        }
        
        // Process in batches to prevent memory exhaustion
        $batch_size = 100; // Process 100 members at a time
        $offset = 0;
        $results = [
            'processed' => 0,
            'notified' => 0,
            'deactivated' => 0,
            'skipped' => 0,
            'errors' => []
        ];
        
        do {
            $members = $this->getUiMembersBatch($batch_size, $offset);
            
            if (empty($members)) {
                break;
            }
            
            foreach ($members as $member) {
                try {
                    // Check memory before each member
                    $current_memory = memory_get_usage(true);
                    if ($current_memory > $memory_threshold) {
                        $this->helper->warning('LGL MembershipRenewalManager: Memory threshold reached, pausing processing', [
                            'current_mb' => round($current_memory / 1024 / 1024, 2),
                            'processed' => $results['processed']
                        ]);
                        // Clear any caches if possible
                        wp_cache_flush();
                        // Reset memory check
                        $current_memory = memory_get_usage(true);
                    }
                    
                    $result = $this->processMemberRenewal($member->ID);
                    $results['processed']++;
                    
                    if ($result['action'] === 'notified') {
                        $results['notified']++;
                    } elseif ($result['action'] === 'deactivated') {
                        $results['deactivated']++;
                    } else {
                        $results['skipped']++;
                    }
                    
                } catch (\Exception $e) {
                    $results['errors'][] = [
                        'user_id' => $member->ID,
                        'error' => $this->getSafeErrorMessage($e)
                    ];
                    $this->helper->error('LGL MembershipRenewalManager: Error processing member', [
                        'user_id' => $member->ID,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            $offset += $batch_size;
            
            // Small delay between batches to prevent overwhelming the system
            if (!empty($members)) {
                usleep(100000); // 0.1 second delay
            }
            
        } while (count($members) === $batch_size);
        
        $this->helper->info('LGL MembershipRenewalManager: Processing completed', [
            'processed' => $results['processed'],
            'notified' => $results['notified'],
            'deactivated' => $results['deactivated'],
            'errors' => count($results['errors'])
        ]);
        
        return $results;
    }
    
    /**
     * Get UI members in batches
     * 
     * @param int $limit Number of members to retrieve
     * @param int $offset Offset for pagination
     * @return array Array of WP_User objects
     */
    private function getUiMembersBatch(int $limit = 100, int $offset = 0): array {
        return get_users([
            'role__in' => ['ui_member', 'ui_patron_owner'],
            'meta_query' => [
                [
                    'key' => 'user-membership-renewal-date',
                    'value' => '',
                    'compare' => '!='
                ]
            ],
            'number' => $limit,
            'offset' => $offset,
            'orderby' => 'ID',
            'order' => 'ASC'
        ]);
    }
    
    /**
     * Get memory limit in bytes
     * 
     * @return int Memory limit in bytes
     */
    private function getMemoryLimitBytes(): int {
        $limit = ini_get('memory_limit');
        if ($limit == -1) {
            return PHP_INT_MAX; // Unlimited
        }
        
        $limit = trim($limit);
        $last = strtolower($limit[strlen($limit) - 1]);
        $value = (int) $limit;
        
        switch ($last) {
            case 'g':
                $value *= 1024;
            case 'm':
                $value *= 1024;
            case 'k':
                $value *= 1024;
        }
        
        return $value;
    }
    
    /**
     * Get safe error message for production (no stack traces)
     * 
     * @param \Exception $e Exception
     * @return string Safe error message
     */
    private function getSafeErrorMessage(\Exception $e): string {
        // In production, return generic message
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return 'An error occurred while processing. Please contact support if this persists.';
        }
        
        // In debug mode, return full message
        return $e->getMessage();
    }
    
    /**
     * Process renewal for a specific member
     * 
     * @param int $user_id User ID
     * @return array Processing result
     */
    public function processMemberRenewal(int $user_id): array {
        if (!$user_id) {
            throw new \InvalidArgumentException('Invalid user ID provided');
        }
        
        $user_data = get_userdata($user_id);
        if (!$user_data) {
            throw new \InvalidArgumentException('User not found: ' . $user_id);
        }
        
        // Check renewal strategy - skip if WooCommerce manages this user
        if ($this->strategyManager && $this->strategyManager->getRenewalStrategy($user_id) === RenewalStrategyManager::STRATEGY_WOOCOMMERCE) {
            return ['action' => 'skipped', 'reason' => 'wc_subscriptions_active'];
        }
        
        // Get renewal data
        $renewal_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
        $subscription_status = get_user_meta($user_id, 'user-subscription-status', true);
        
        if (empty($renewal_timestamp)) {
            return ['action' => 'skipped', 'reason' => 'no_renewal_date'];
        }
        
        // Only process in-person memberships for now
        if ($subscription_status !== 'in-person') {
            return ['action' => 'skipped', 'reason' => 'not_in_person'];
        }
        
        $renewal_date = new \DateTime('@' . $renewal_timestamp, new \DateTimeZone('America/New_York'));
        $today = new \DateTime();
        $interval = $today->diff($renewal_date);
        $days_until_renewal = (int) $interval->format('%r%a');
        
        // Check if action is needed
        if ($days_until_renewal === -self::GRACE_PERIOD_DAYS) {
            // Grace period expired - deactivate membership
            return $this->deactivateMembership($user_id, $user_data);
            
        } elseif (in_array($days_until_renewal, self::NOTIFICATION_INTERVALS)) {
            // Send renewal notification
            return $this->sendRenewalNotification($user_id, $user_data, $days_until_renewal);
        }
        
        return ['action' => 'no_action', 'days_until_renewal' => $days_until_renewal];
    }
    
    /**
     * Send renewal notification to member
     * 
     * @param int $user_id User ID
     * @param \WP_User $user_data User data object
     * @param int $days_until_renewal Days until renewal (negative if overdue)
     * @return array Action result
     */
    private function sendRenewalNotification(int $user_id, \WP_User $user_data, int $days_until_renewal): array {
        $user_email = $user_data->user_email;
        $first_name = ucfirst(get_user_meta($user_id, 'first_name', true) ?: $user_data->display_name);
        
        $notification_sent = $this->mailer->sendRenewalNotification(
            $user_email,
            $first_name,
            $days_until_renewal
        );
        
        if ($notification_sent) {
            $this->helper->info('LGL MembershipRenewalManager: Renewal notification sent', [
                'user_id' => $user_id,
                'days_until_renewal' => $days_until_renewal
            ]);
            return [
                'action' => 'notified',
                'days_until_renewal' => $days_until_renewal,
                'email' => $user_email
            ];
        } else {
            throw new \RuntimeException('Failed to send renewal notification');
        }
    }
    
    /**
     * Deactivate expired membership
     * 
     * @param int $user_id User ID
     * @param \WP_User $user_data User data object
     * @return array Action result
     */
    private function deactivateMembership(int $user_id, \WP_User $user_data): array {
        // Send final notification
        $user_email = $user_data->user_email;
        $first_name = ucfirst(get_user_meta($user_id, 'first_name', true) ?: $user_data->display_name);
        
        $this->mailer->sendRenewalNotification($user_email, $first_name, -self::GRACE_PERIOD_DAYS);
        
        // Deactivate user through WP Users service
        $this->wpUsers->userDeactivation($user_id, 'membership_expired');
        
        $this->helper->info('LGL MembershipRenewalManager: Membership deactivated', [
            'user_id' => $user_id
        ]);
        
        return [
            'action' => 'deactivated',
            'user_id' => $user_id,
            'email' => $user_email
        ];
    }
    
    /**
     * Get all UI members (ui_member and ui_patron_owner roles)
     * 
     * @return array Array of WP_User objects
     */
    private function getUiMembers(): array {
        return get_users([
            'role__in' => ['ui_member', 'ui_patron_owner'],
            'meta_query' => [
                [
                    'key' => 'user-membership-renewal-date',
                    'value' => '',
                    'compare' => '!='
                ]
            ]
        ]);
    }
    
    /**
     * Check if user needs renewal notification
     * 
     * @param int $user_id User ID
     * @return array|null Renewal info or null if no action needed
     */
    public function checkRenewalStatus(int $user_id): ?array {
        $renewal_timestamp = get_user_meta($user_id, 'user-membership-renewal-date', true);
        if (empty($renewal_timestamp)) {
            return null;
        }
        
        $renewal_date = new \DateTime('@' . $renewal_timestamp, new \DateTimeZone('America/New_York'));
        $today = new \DateTime();
        $interval = $today->diff($renewal_date);
        $days_until_renewal = (int) $interval->format('%r%a');
        
        return [
            'user_id' => $user_id,
            'renewal_date' => $renewal_date->format('Y-m-d'),
            'days_until_renewal' => $days_until_renewal,
            'needs_notification' => in_array($days_until_renewal, self::NOTIFICATION_INTERVALS),
            'is_expired' => $days_until_renewal < -self::GRACE_PERIOD_DAYS
        ];
    }
    
    /**
     * Get renewal statistics
     * 
     * @return array Statistics about member renewals
     */
    public function getRenewalStatistics(): array {
        $members = $this->getUiMembers();
        $stats = [
            'total_members' => count($members),
            'due_soon' => 0,
            'overdue' => 0,
            'expired' => 0,
            'current' => 0
        ];
        
        foreach ($members as $member) {
            $status = $this->checkRenewalStatus($member->ID);
            if (!$status) {
                continue;
            }
            
            $days = $status['days_until_renewal'];
            
            if ($days < -self::GRACE_PERIOD_DAYS) {
                $stats['expired']++;
            } elseif ($days < 0) {
                $stats['overdue']++;
            } elseif ($days <= 30) {
                $stats['due_soon']++;
            } else {
                $stats['current']++;
            }
        }
        
        return $stats;
    }
}
