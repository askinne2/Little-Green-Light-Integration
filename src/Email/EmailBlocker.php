<?php
/**
 * Email Blocker for Development Environments
 * 
 * Prevents emails from being sent in local/development environments while allowing
 * them in production. Provides proper logging and debugging capabilities.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

/**
 * Email Blocker Class
 * 
 * Manages email blocking based on environment detection
 */
class EmailBlocker {
    
    /**
     * Development domains and indicators
     */
    const DEV_INDICATORS = [
        '.local',
        'localhost',
        '127.0.0.1',
        '.dev',
        '.test',
        'staging',
        'development'
    ];
    
    /**
     * Initialize email blocker
     */
    public static function init(): void {
        if (static::isDevelopmentEnvironment()) {
            add_filter('wp_mail', [static::class, 'blockEmails'], 999);
            add_action('admin_notices', [static::class, 'showEmailBlockingNotice']);
            
            error_log('LGL Email Blocker: ACTIVE - Development environment detected');
        } else {
            error_log('LGL Email Blocker: INACTIVE - Production environment detected');
        }
        
        // Always add admin page for blocked emails management
        add_action('admin_menu', [static::class, 'addAdminPage']);
    }
    
    /**
     * Check if we're in a development environment
     * 
     * @return bool True if development environment
     */
    public static function isDevelopmentEnvironment(): bool {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $site_url = get_site_url();
        
        // Check for development indicators
        foreach (self::DEV_INDICATORS as $indicator) {
            if (strpos($host, $indicator) !== false || strpos($site_url, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for WordPress debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        // Check for local IP addresses
        if (in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Block emails and log attempts
     * 
     * @param array $args Email arguments
     * @return false Always returns false to block email
     */
    public static function blockEmails(array $args) {
        $subject = $args['subject'] ?? 'No Subject';
        $to = is_array($args['to']) ? implode(', ', $args['to']) : ($args['to'] ?? 'Unknown');
        
        // Log the blocked email attempt
        error_log(sprintf(
            'LGL Email Blocker: BLOCKED email - To: %s | Subject: %s | Environment: %s',
            $to,
            $subject,
            static::getEnvironmentInfo()
        ));
        
        // Store blocked email for admin review (optional)
        static::storeBlockedEmail($args);
        
        // Return false to prevent email sending
        return false;
    }
    
    /**
     * Store blocked email for admin review
     * 
     * @param array $args Email arguments
     */
    private static function storeBlockedEmail(array $args): void {
        $blocked_emails = get_option('lgl_blocked_emails', []);
        
        // Keep only last 50 blocked emails
        if (count($blocked_emails) >= 50) {
            $blocked_emails = array_slice($blocked_emails, -49);
        }
        
        $blocked_emails[] = [
            'timestamp' => current_time('mysql'),
            'to' => $args['to'] ?? 'Unknown',
            'subject' => $args['subject'] ?? 'No Subject',
            'message_preview' => substr(strip_tags($args['message'] ?? ''), 0, 200),
            'headers' => $args['headers'] ?? [],
        ];
        
        update_option('lgl_blocked_emails', $blocked_emails);
    }
    
    /**
     * Get environment information
     * 
     * @return string Environment description
     */
    private static function getEnvironmentInfo(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
        $debug_status = defined('WP_DEBUG') && WP_DEBUG ? 'DEBUG_ON' : 'DEBUG_OFF';
        
        return sprintf('%s (%s)', $host, $debug_status);
    }
    
    /**
     * Show admin notice about email blocking
     */
    public static function showEmailBlockingNotice(): void {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $blocked_count = count(get_option('lgl_blocked_emails', []));
        
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>🚫 LGL Email Blocker Active</strong></p>';
        echo '<p>Emails are being blocked in this development environment. ';
        echo sprintf('Blocked %d emails since activation. ', $blocked_count);
        echo '<a href="' . admin_url('admin.php?page=lgl-blocked-emails') . '">View blocked emails</a></p>';
        echo '</div>';
    }
    
    /**
     * Add admin page to view blocked emails
     */
    public static function addAdminPage(): void {
        add_submenu_page(
            'tools.php',
            'Blocked Emails',
            'Blocked Emails',
            'manage_options',
            'lgl-blocked-emails',
            [static::class, 'renderBlockedEmailsPage']
        );
    }
    
    /**
     * Render blocked emails admin page
     */
    public static function renderBlockedEmailsPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }
        
        // Handle clear action
        if (isset($_POST['clear_blocked_emails']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_blocked_emails')) {
            delete_option('lgl_blocked_emails');
            echo '<div class="updated"><p>Blocked emails cleared.</p></div>';
        }
        
        $blocked_emails = get_option('lgl_blocked_emails', []);
        $blocked_emails = array_reverse($blocked_emails); // Show newest first
        
        echo '<div class="wrap">';
        echo '<h1>🚫 Blocked Emails (Development Environment)</h1>';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>Environment:</strong> ' . static::getEnvironmentInfo() . '</p>';
        echo '<p><strong>Status:</strong> Email blocking is ' . (static::isDevelopmentEnvironment() ? '<span style="color: red;">ACTIVE</span>' : '<span style="color: green;">INACTIVE</span>') . '</p>';
        echo '</div>';
        
        if (empty($blocked_emails)) {
            echo '<p>No emails have been blocked yet.</p>';
        } else {
            echo '<p><strong>' . count($blocked_emails) . '</strong> emails blocked since activation.</p>';
            
            // Clear button
            echo '<form method="post" style="margin-bottom: 20px;">';
            wp_nonce_field('clear_blocked_emails');
            echo '<input type="submit" name="clear_blocked_emails" value="Clear All Blocked Emails" class="button button-secondary" onclick="return confirm(\'Are you sure you want to clear all blocked emails?\');">';
            echo '</form>';
            
            // Blocked emails table
            echo '<table class="widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th style="width: 150px;">Date/Time</th>';
            echo '<th style="width: 200px;">To</th>';
            echo '<th>Subject</th>';
            echo '<th>Message Preview</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($blocked_emails as $email) {
                echo '<tr>';
                echo '<td>' . esc_html($email['timestamp']) . '</td>';
                echo '<td>' . esc_html(is_array($email['to']) ? implode(', ', $email['to']) : $email['to']) . '</td>';
                echo '<td><strong>' . esc_html($email['subject']) . '</strong></td>';
                echo '<td>' . esc_html($email['message_preview']) . '...</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }
    
    /**
     * Get blocked email statistics
     * 
     * @return array Statistics about blocked emails
     */
    public static function getStats(): array {
        $blocked_emails = get_option('lgl_blocked_emails', []);
        
        return [
            'total_blocked' => count($blocked_emails),
            'is_blocking' => static::isDevelopmentEnvironment(),
            'environment' => static::getEnvironmentInfo(),
            'recent_blocks' => array_slice(array_reverse($blocked_emails), 0, 5)
        ];
    }
    
    /**
     * Temporarily disable email blocking (for testing)
     * 
     * @param int $duration Duration in seconds
     */
    public static function temporarilyDisable(int $duration = 300): void {
        set_transient('lgl_email_blocking_disabled', true, $duration);
        error_log('LGL Email Blocker: TEMPORARILY DISABLED for ' . $duration . ' seconds');
    }
    
    /**
     * Check if email blocking is temporarily disabled
     * 
     * @return bool True if temporarily disabled
     */
    public static function isTemporarilyDisabled(): bool {
        return get_transient('lgl_email_blocking_disabled') !== false;
    }
    
    /**
     * Enable email blocking (for testing)
     */
    public static function enable(): void {
        delete_transient('lgl_email_blocking_disabled');
        error_log('LGL Email Blocker: RE-ENABLED');
    }
    
    /**
     * Get current blocking status
     * 
     * @return array Status information
     */
    public static function getBlockingStatus(): array {
        return [
            'is_development' => static::isDevelopmentEnvironment(),
            'is_temporarily_disabled' => static::isTemporarilyDisabled(),
            'is_actively_blocking' => static::isDevelopmentEnvironment() && !static::isTemporarilyDisabled(),
            'environment_info' => static::getEnvironmentInfo()
        ];
    }
}
