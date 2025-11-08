<?php
/**
 * Email Blocker for Development Environments (Legacy)
 * 
 * This is the legacy procedural version of the Email Blocker that runs early in plugin initialization
 * as a fail-safe. It mirrors the logic in src/Email/EmailBlocker.php but reads settings directly
 * from SettingsManager's storage location.
 * 
 * The modern OOP version at src/Email/EmailBlocker.php is the primary implementation.
 * This legacy version ensures email blocking works even if the service container fails.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Email Blocker Class (Legacy)
 * 
 * Manages email blocking based on environment detection
 * Mirrors src/Email/EmailBlocker.php for legacy compatibility
 */
class LGL_Email_Blocker {
    
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
    public static function init() {
        if (self::is_blocking_enabled()) {
            add_filter('wp_mail', [self::class, 'block_emails'], 999);
            add_action('admin_notices', [self::class, 'show_email_blocking_notice']);
            
            $mode = self::is_force_blocking() ? 'MANUAL OVERRIDE ENABLED' : 'Development environment detected';
            error_log('LGL Email Blocker (Legacy): ACTIVE - ' . $mode);
        } else {
            error_log('LGL Email Blocker (Legacy): INACTIVE - Manual override disabled and environment not flagged');
        }
    }
    
    /**
     * Check if we're in a development environment
     * 
     * @return bool True if development environment
     */
    public static function is_development_environment() {
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
        $site_url = get_site_url();
        
        // Check for development indicators
        foreach (self::DEV_INDICATORS as $indicator) {
            if (strpos($host, $indicator) !== false || strpos($site_url, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for local IP addresses
        if (in_array($_SERVER['SERVER_ADDR'] ?? '', ['127.0.0.1', '::1'])) {
            return true;
        }
        
        return false;
    }

    /**
     * Determine whether email blocking should be active
     * 
     * @return bool
     */
    public static function is_blocking_enabled() {
        if (self::is_force_blocking()) {
            return true;
        }

        return self::is_development_environment();
    }

    /**
     * Determine whether manual blocking override is active
     * Reads from SettingsManager's storage location
     * 
     * @return bool
     */
    public static function is_force_blocking() {
        // Read from SettingsManager location (primary)
        $settings = get_option('lgl_integration_settings', []);
        if (isset($settings['force_email_blocking'])) {
            return (bool) $settings['force_email_blocking'];
        }
        
        // Fallback to old standalone option for backward compatibility
        return (bool) get_option('lgl_force_email_blocking', false);
    }
    
    /**
     * Block emails and log attempts
     * 
     * @param array $args Email arguments
     * @return false Always returns false to block email
     */
    public static function block_emails($args) {
        // Allow temporarily if disabled
        if (self::is_temporarily_disabled()) {
            error_log(sprintf(
                'LGL Email Blocker (Legacy): ALLOWED (temporarily disabled) - To: %s | Subject: %s',
                is_array($args['to'] ?? null) ? implode(', ', $args['to']) : ($args['to'] ?? 'Unknown'),
                $args['subject'] ?? 'No Subject'
            ));
            return $args;
        }

        $subject = $args['subject'] ?? 'No Subject';
        $to_raw = $args['to'] ?? 'Unknown';
        $to = is_array($to_raw) ? implode(', ', $to_raw) : $to_raw;

        if (self::is_whitelisted($to_raw)) {
            error_log(sprintf(
                'LGL Email Blocker (Legacy): ALLOWED (whitelisted) - To: %s | Subject: %s',
                $to,
                $subject
            ));
            return $args;
        }
        
        // Log the blocked email attempt
        error_log(sprintf(
            'LGL Email Blocker (Legacy): BLOCKED email - To: %s | Subject: %s | Environment: %s | Mode: %s',
            $to,
            $subject,
            self::get_environment_info(),
            self::is_force_blocking() ? 'manual_override' : 'environment'
        ));
        
        // Store blocked email for admin review (optional)
        self::store_blocked_email($args);
        
        // Return false to prevent email sending
        return false;
    }
    
    /**
     * Check if email address is whitelisted
     * Reads from SettingsManager's storage location
     * 
     * @param mixed $email_field Email address (string or array)
     * @return bool True if whitelisted
     */
    private static function is_whitelisted($email_field) {
        $emails = [];
        if (is_array($email_field)) {
            $emails = $email_field;
        } elseif (is_string($email_field)) {
            $emails = array_map('trim', explode(',', $email_field));
        }

        if (empty($emails)) {
            return false;
        }

        $admin_email = get_option('admin_email');
        
        // Read from SettingsManager location (primary)
        $settings = get_option('lgl_integration_settings', []);
        $whitelist = $settings['email_whitelist'] ?? [];
        
        // Fallback to old standalone option for backward compatibility
        if (empty($whitelist)) {
            $whitelist = (array) get_option('lgl_email_whitelist', []);
        }

        foreach ($emails as $email) {
            if ($email === $admin_email) {
                return true;
            }
            if (in_array($email, $whitelist, true)) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Whitelist management removed - now handled by EmailBlockingSettingsPage
     * through SettingsManager in the modern implementation
     */
    
    /**
     * Store blocked email for admin review
     * 
     * @param array $args Email arguments
     */
    private static function store_blocked_email($args) {
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
    private static function get_environment_info() {
        $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
        $debug_status = defined('WP_DEBUG') && WP_DEBUG ? 'DEBUG_ON' : 'DEBUG_OFF';
        
        return sprintf('%s (%s)', $host, $debug_status);
    }
    
    /**
     * Show admin notice about email blocking
     */
    public static function show_email_blocking_notice() {
        if (!self::is_blocking_enabled()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }
        
        $blocked_count = count(get_option('lgl_blocked_emails', []));
        
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>🚫 LGL Email Blocker Active</strong></p>';
        echo '<p>Emails are being blocked by the LGL Email Blocker. ';
        echo sprintf('Blocked %d emails since activation. ', $blocked_count);
        if (self::is_force_blocking()) {
            echo '<span style="color:#d63638;"><strong>Manual override is forcing all emails to be blocked.</strong></span> ';
        } else {
            echo 'Environment heuristics detected a non-production site. ';
        }
        echo '<a href="' . admin_url('admin.php?page=lgl-email-blocking') . '">Manage email blocking</a></p>';
        echo '</div>';
    }
    
    /**
     * Add admin page to view blocked emails
     */
    public static function add_admin_page() {
        add_submenu_page(
            'tools.php',
            'Blocked Emails',
            'Blocked Emails',
            'manage_options',
            'lgl-blocked-emails',
            [self::class, 'render_blocked_emails_page']
        );
    }
    
    /**
     * Render blocked emails admin page
     */
    public static function render_blocked_emails_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        // Handle force blocking toggle
        if (isset($_POST['save_force_blocking']) && wp_verify_nonce($_POST['_wpnonce'], 'toggle_force_blocking')) {
            $force_blocking = isset($_POST['force_blocking']);
            self::set_force_blocking($force_blocking);
            echo '<div class="updated"><p>Email blocking preference updated. ' . ($force_blocking ? 'All outgoing emails will now be blocked until you disable this option.' : 'Manual override disabled — environment detection will determine blocking status.') . '</p></div>';
        }
        
        // Handle clear action
        if (isset($_POST['clear_blocked_emails']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_blocked_emails')) {
            delete_option('lgl_blocked_emails');
            echo '<div class="updated"><p>Blocked emails cleared.</p></div>';
        }
        
        $blocked_emails = get_option('lgl_blocked_emails', []);
        $blocked_emails = array_reverse($blocked_emails); // Show newest first
        
        echo '<div class="wrap">';
        echo '<h1>🚫 Blocked Emails</h1>';
        
        echo '<div class="notice notice-info">';
        echo '<p><strong>Environment:</strong> ' . self::get_environment_info() . '</p>';
        if (self::is_force_blocking()) {
            echo '<p><strong>Status:</strong> <span style="color: red;">ACTIVE</span> — Manual override is forcing all emails to be blocked.</p>';
        } else {
            echo '<p><strong>Status:</strong> Email blocking is ' . (self::is_development_environment() ? '<span style="color: red;">ACTIVE</span>' : '<span style="color: green;">INACTIVE</span>') . ' (based on environment detection)</p>';
        }
        if (self::is_temporarily_disabled()) {
            echo '<p><strong>Temporary Override:</strong> Blocking is currently paused via temporary disable.</p>';
        }
        echo '<p><strong>Whitelisted Emails:</strong> Admin email (' . esc_html(get_option('admin_email')) . ') is always allowed';
        $whitelist = get_option('lgl_email_whitelist', []);
        if (!empty($whitelist)) {
            echo ', plus: ' . implode(', ', array_map('esc_html', $whitelist));
        }
        echo '.</p>';
        echo '<p><em>💡 Tip: Use the <a href="' . admin_url('admin.php?page=lgl-test-renewals') . '">Membership Testing Tools</a> to safely test renewal emails.</em></p>';
        echo '</div>';

        echo '<form method="post" style="margin-bottom: 20px;">';
        wp_nonce_field('toggle_force_blocking');
        $force_checked = self::is_force_blocking() ? 'checked="checked"' : '';
        echo '<label style="display:flex;align-items:center;gap:8px;margin-bottom:8px;"><input type="checkbox" name="force_blocking" value="1" ' . $force_checked . '> <strong>Force block all outgoing emails</strong></label>';
        echo '<p class="description" style="margin-top:0;">When enabled, every email sent via <code>wp_mail()</code> will be blocked regardless of environment detection. Use this when you need a hard stop.</p>';
        submit_button('Save Blocking Preference', 'primary', 'save_force_blocking', false);
        echo '</form>';
        
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
    public static function get_stats() {
        $blocked_emails = get_option('lgl_blocked_emails', []);
        
        return [
            'total_blocked' => count($blocked_emails),
            'is_blocking' => self::is_blocking_enabled(),
            'is_forced' => self::is_force_blocking(),
            'environment' => self::get_environment_info(),
            'recent_blocks' => array_slice(array_reverse($blocked_emails), 0, 5)
        ];
    }
    
    /**
     * Temporarily disable email blocking (for testing)
     * 
     * @param int $duration Duration in seconds
     */
    public static function temporarily_disable($duration = 300) {
        set_transient('lgl_email_blocking_disabled', true, $duration);
        error_log('LGL Email Blocker: TEMPORARILY DISABLED for ' . $duration . ' seconds');
    }
    
    /**
     * Check if email blocking is temporarily disabled
     * 
     * @return bool True if temporarily disabled
     */
    public static function is_temporarily_disabled() {
        return get_transient('lgl_email_blocking_disabled') !== false;
    }
}

// Initialize email blocker (legacy fail-safe)
// DISABLED: The modern OOP version in src/Email/EmailBlocker.php is now the primary implementation
// This legacy version has been disabled to avoid conflicts with the modern version
// LGL_Email_Blocker::init();
