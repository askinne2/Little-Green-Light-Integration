<?php
/**
 * Membership Cron Manager
 * 
 * Handles WordPress cron job scheduling and execution for membership renewal notifications.
 * Modernized version of UI_Memberships cron functionality with proper error handling.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Memberships;

use UpstateInternational\LGL\LGL\Helper;

/**
 * MembershipCronManager Class
 * 
 * Manages WordPress cron jobs for automated membership renewal processing
 */
class MembershipCronManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Membership renewal manager
     * 
     * @var MembershipRenewalManager
     */
    private MembershipRenewalManager $renewalManager;
    
    /**
     * Daily cron hook name
     */
    const DAILY_CRON_HOOK = 'ui_memberships_daily_cron_hook';
    
    /**
     * Weekly cron hook name
     */
    const WEEKLY_CRON_HOOK = 'ui_memberships_weekly_cron_hook';
    
    /**
     * Cleanup cron hook name
     */
    const CLEANUP_CRON_HOOK = 'ui_memberships_cleanup_cron_hook';
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param MembershipRenewalManager $renewalManager Renewal manager
     */
    public function __construct(Helper $helper, MembershipRenewalManager $renewalManager) {
        $this->helper = $helper;
        $this->renewalManager = $renewalManager;
        
        $this->initializeCronJobs();
    }
    
    /**
     * Initialize cron job hooks
     * 
     * @return void
     */
    private function initializeCronJobs(): void {
        // Register cron hooks
        add_action(self::DAILY_CRON_HOOK, [$this, 'runDailyMembershipCheck']);
        add_action(self::WEEKLY_CRON_HOOK, [$this, 'runWeeklyStatisticsUpdate']);
        add_action(self::CLEANUP_CRON_HOOK, [$this, 'runCleanupTasks']);
        
        // Schedule cron jobs if not already scheduled
        add_action('wp', [$this, 'scheduleCronJobs']);
    }
    
    /**
     * Schedule all cron jobs if not already scheduled
     * 
     * @return void
     */
    public function scheduleCronJobs(): void {
        // Schedule daily membership check
        if (!wp_next_scheduled(self::DAILY_CRON_HOOK)) {
            $this->scheduleDailyCheck();
        }
        
        // Schedule weekly statistics update
        if (!wp_next_scheduled(self::WEEKLY_CRON_HOOK)) {
            $this->scheduleWeeklyStats();
        }
        
        // Schedule monthly cleanup
        if (!wp_next_scheduled(self::CLEANUP_CRON_HOOK)) {
            $this->scheduleCleanup();
        }
        
        $this->helper->debug('Membership cron jobs scheduled successfully');
    }
    
    /**
     * Schedule daily membership renewal check
     * 
     * @return bool Success status
     */
    public function scheduleDailyCheck(): bool {
        // Schedule for 9 AM daily
        $start_time = strtotime('tomorrow 9:00 AM');
        
        $scheduled = wp_schedule_event($start_time, 'daily', self::DAILY_CRON_HOOK);
        
        if ($scheduled !== false) {
            $this->helper->debug('Daily membership check scheduled for ' . date('Y-m-d H:i:s', $start_time));
            return true;
        } else {
            $this->helper->debug('Failed to schedule daily membership check');
            return false;
        }
    }
    
    /**
     * Schedule weekly statistics update
     * 
     * @return bool Success status
     */
    public function scheduleWeeklyStats(): bool {
        // Schedule for Mondays at 8 AM
        $start_time = strtotime('next Monday 8:00 AM');
        
        $scheduled = wp_schedule_event($start_time, 'weekly', self::WEEKLY_CRON_HOOK);
        
        if ($scheduled !== false) {
            $this->helper->debug('Weekly statistics update scheduled for ' . date('Y-m-d H:i:s', $start_time));
            return true;
        } else {
            $this->helper->debug('Failed to schedule weekly statistics update');
            return false;
        }
    }
    
    /**
     * Schedule monthly cleanup tasks
     * 
     * @return bool Success status
     */
    public function scheduleCleanup(): bool {
        // Schedule for first day of month at 6 AM
        $start_time = strtotime('first day of next month 6:00 AM');
        
        // Use a custom interval for monthly
        $scheduled = wp_schedule_event($start_time, 'monthly', self::CLEANUP_CRON_HOOK);
        
        if ($scheduled !== false) {
            $this->helper->debug('Monthly cleanup scheduled for ' . date('Y-m-d H:i:s', $start_time));
            return true;
        } else {
            $this->helper->debug('Failed to schedule monthly cleanup');
            return false;
        }
    }
    
    /**
     * Run daily membership renewal check
     * 
     * This is the main cron job that processes all members for renewal notifications
     * 
     * @return void
     */
    public function runDailyMembershipCheck(): void {
        $this->helper->debug('Starting daily membership renewal check');
        
        try {
            $start_time = microtime(true);
            
            // Process all members
            $results = $this->renewalManager->processAllMembers();
            
            $execution_time = round((microtime(true) - $start_time) * 1000, 2);
            
            // Log results
            $this->helper->debug('Daily membership check completed', [
                'execution_time_ms' => $execution_time,
                'results' => $results
            ]);
            
            // Store results for admin dashboard
            $this->storeLastRunResults($results);
            
            // Send admin notification if there were errors
            if (!empty($results['errors'])) {
                $this->sendAdminNotification($results);
            }
            
        } catch (\Exception $e) {
            $this->helper->debug('Daily membership check failed: ' . $e->getMessage());
            $this->sendAdminErrorNotification($e);
        }
    }
    
    /**
     * Run weekly statistics update
     * 
     * @return void
     */
    public function runWeeklyStatisticsUpdate(): void {
        $this->helper->debug('Starting weekly membership statistics update');
        
        try {
            $stats = $this->renewalManager->getRenewalStatistics();
            
            // Store statistics for dashboard
            update_option('ui_membership_weekly_stats', [
                'date' => current_time('Y-m-d H:i:s'),
                'stats' => $stats
            ]);
            
            $this->helper->debug('Weekly statistics updated', $stats);
            
        } catch (\Exception $e) {
            $this->helper->debug('Weekly statistics update failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Run cleanup tasks (monthly)
     * 
     * @return void
     */
    public function runCleanupTasks(): void {
        $this->helper->debug('Starting monthly membership cleanup tasks');
        
        try {
            // Clean up old transients
            $this->cleanupTransients();
            
            // Clean up old log entries
            $this->cleanupLogs();
            
            // Clean up expired user meta
            $this->cleanupExpiredUserMeta();
            
            $this->helper->debug('Monthly cleanup tasks completed');
            
        } catch (\Exception $e) {
            $this->helper->debug('Monthly cleanup failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Store last run results for admin dashboard
     * 
     * @param array $results Processing results
     * @return void
     */
    private function storeLastRunResults(array $results): void {
        $data = [
            'timestamp' => current_time('timestamp'),
            'date' => current_time('Y-m-d H:i:s'),
            'results' => $results
        ];
        
        update_option('ui_membership_last_run', $data);
    }
    
    /**
     * Send admin notification about errors
     * 
     * @param array $results Processing results with errors
     * @return void
     */
    private function sendAdminNotification(array $results): void {
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }
        
        $error_count = count($results['errors']);
        $subject = "UI Memberships: {$error_count} errors in daily processing";
        
        $message = "The daily membership renewal check encountered {$error_count} errors:\n\n";
        
        foreach ($results['errors'] as $error) {
            $message .= "User ID {$error['user_id']}: {$error['error']}\n";
        }
        
        $message .= "\nProcessing Summary:\n";
        $message .= "- Processed: {$results['processed']}\n";
        $message .= "- Notified: {$results['notified']}\n";
        $message .= "- Deactivated: {$results['deactivated']}\n";
        $message .= "- Skipped: {$results['skipped']}\n";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Send admin notification about critical errors
     * 
     * @param \Exception $exception The exception that occurred
     * @return void
     */
    private function sendAdminErrorNotification(\Exception $exception): void {
        $admin_email = get_option('admin_email');
        if (!$admin_email) {
            return;
        }
        
        $subject = 'UI Memberships: Critical Error in Daily Processing';
        $message = "A critical error occurred during daily membership processing:\n\n";
        $message .= "Error: {$exception->getMessage()}\n";
        $message .= "File: {$exception->getFile()}\n";
        $message .= "Line: {$exception->getLine()}\n\n";
        $message .= "Please check the logs and resolve this issue immediately.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Clean up old transients
     * 
     * @return void
     */
    private function cleanupTransients(): void {
        global $wpdb;
        
        // Clean up expired transients older than 30 days in batches to prevent memory issues
        $batch_size = 1000;
        $deleted_total = 0;
        
        do {
            $deleted = $wpdb->query($wpdb->prepare("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_timeout_ui_membership_%' 
                AND option_value < %d
                LIMIT %d
            ", time() - (30 * DAY_IN_SECONDS), $batch_size));
            
            $deleted_total += $deleted;
            
            // Small delay between batches
            if ($deleted > 0) {
                usleep(50000); // 0.05 second delay
            }
        } while ($deleted === $batch_size);
        
        $this->helper->debug('Old membership transients cleaned up', [
            'deleted_count' => $deleted_total
        ]);
    }
    
    /**
     * Clean up old log entries
     * 
     * @return void
     */
    private function cleanupLogs(): void {
        // Clean up log entries older than 90 days
        $old_logs = get_option('ui_membership_processing_logs', []);
        $cutoff_date = time() - (90 * DAY_IN_SECONDS);
        
        $cleaned_logs = array_filter($old_logs, function($log) use ($cutoff_date) {
            return isset($log['timestamp']) && $log['timestamp'] > $cutoff_date;
        });
        
        update_option('ui_membership_processing_logs', $cleaned_logs);
        
        $this->helper->debug('Old membership logs cleaned up');
    }
    
    /**
     * Clean up expired user meta
     * 
     * @return void
     */
    private function cleanupExpiredUserMeta(): void {
        // This could be expanded to clean up specific expired meta fields
        // For now, just log that cleanup ran
        $this->helper->debug('User meta cleanup completed');
    }
    
    /**
     * Unschedule all cron jobs
     * 
     * @return void
     */
    public function unscheduleAllJobs(): void {
        wp_clear_scheduled_hook(self::DAILY_CRON_HOOK);
        wp_clear_scheduled_hook(self::WEEKLY_CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_CRON_HOOK);
        
        $this->helper->debug('All membership cron jobs unscheduled');
    }
    
    /**
     * Get cron job status
     * 
     * @return array Cron job information
     */
    public function getCronStatus(): array {
        return [
            'daily_check' => [
                'hook' => self::DAILY_CRON_HOOK,
                'scheduled' => wp_next_scheduled(self::DAILY_CRON_HOOK),
                'next_run' => wp_next_scheduled(self::DAILY_CRON_HOOK) ? 
                    date('Y-m-d H:i:s', wp_next_scheduled(self::DAILY_CRON_HOOK)) : 'Not scheduled'
            ],
            'weekly_stats' => [
                'hook' => self::WEEKLY_CRON_HOOK,
                'scheduled' => wp_next_scheduled(self::WEEKLY_CRON_HOOK),
                'next_run' => wp_next_scheduled(self::WEEKLY_CRON_HOOK) ? 
                    date('Y-m-d H:i:s', wp_next_scheduled(self::WEEKLY_CRON_HOOK)) : 'Not scheduled'
            ],
            'cleanup' => [
                'hook' => self::CLEANUP_CRON_HOOK,
                'scheduled' => wp_next_scheduled(self::CLEANUP_CRON_HOOK),
                'next_run' => wp_next_scheduled(self::CLEANUP_CRON_HOOK) ? 
                    date('Y-m-d H:i:s', wp_next_scheduled(self::CLEANUP_CRON_HOOK)) : 'Not scheduled'
            ],
            'last_run' => get_option('ui_membership_last_run', null)
        ];
    }
    
    /**
     * Manually trigger daily check (for testing)
     * 
     * @return array Processing results
     */
    public function triggerManualCheck(): array {
        $this->helper->debug('Manual membership check triggered');
        
        try {
            $results = $this->renewalManager->processAllMembers();
            $this->storeLastRunResults($results);
            return $results;
            
        } catch (\Exception $e) {
            $this->helper->debug('Manual membership check failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
