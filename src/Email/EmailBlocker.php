<?php
/**
 * Email Blocker for Development Environments
 * 
 * Prevents emails from being sent in local/development environments while allowing
 * them in production. Provides proper logging and debugging capabilities.
 * 
 * Now integrated with SettingsManager and OperationalDataManager for centralized data management.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\Admin\SettingsManager;
use UpstateInternational\LGL\Admin\OperationalDataManager;
use UpstateInternational\LGL\LGL\Helper;

/**
 * Email Blocker Class
 * 
 * Manages email blocking based on environment detection and user settings
 * Supports tiered blocking levels: all, woocommerce_allowed, cron_only
 */
class EmailBlocker {
    
    /**
     * Blocking levels
     */
    const LEVEL_BLOCK_ALL = 'all';
    const LEVEL_WOOCOMMERCE_ALLOWED = 'woocommerce_allowed';
    const LEVEL_CRON_ONLY = 'cron_only';
    
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
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Settings manager
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Operational data manager
     * 
     * @var OperationalDataManager
     */
    private OperationalDataManager $operationalData;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param SettingsManager $settingsManager Settings manager instance
     * @param OperationalDataManager $operationalData Operational data manager instance
     */
    public function __construct(Helper $helper, SettingsManager $settingsManager, OperationalDataManager $operationalData) {
        $this->helper = $helper;
        $this->settingsManager = $settingsManager;
        $this->operationalData = $operationalData;
    }
    
    /**
     * Initialize email blocker
     */
    public function init(): void {
        $blockingLevel = $this->getBlockingLevel();
        
        // Only initialize if blocking is enabled (not 'none' or empty)
        if ($blockingLevel && $blockingLevel !== 'none') {
            add_filter('wp_mail', [$this, 'blockEmails'], 999);
            add_action('admin_notices', [$this, 'showEmailBlockingNotice']);
            
            $mode = $this->isForceBlocking() ? 'MANUAL OVERRIDE ENABLED' : 'Development environment detected';
            $this->helper->debug('LGL Email Blocker: ACTIVE - ' . $mode . ' (Level: ' . $blockingLevel . ')');
        } else {
            $this->helper->debug('LGL Email Blocker: INACTIVE - Blocking disabled');
        }
    }
    
    /**
     * Get current blocking level
     * 
     * @return string Blocking level
     */
    public function getBlockingLevel(): string {
        // Check if blocking is explicitly disabled
        if (!$this->isBlockingEnabled()) {
            return 'none';
        }
        
        // Get blocking level from settings, default to 'all' for backward compatibility
        $level = $this->settingsManager->get('email_blocking_level', self::LEVEL_BLOCK_ALL);
        
        // Validate level
        $validLevels = [self::LEVEL_BLOCK_ALL, self::LEVEL_WOOCOMMERCE_ALLOWED, self::LEVEL_CRON_ONLY];
        if (!in_array($level, $validLevels, true)) {
            return self::LEVEL_BLOCK_ALL;
        }
        
        return $level;
    }
    
    /**
     * Check if we're in a development environment
     * 
     * @return bool True if development environment
     */
    public function isDevelopmentEnvironment(): bool {
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
    public function isBlockingEnabled(): bool {
        if ($this->isForceBlocking()) {
            return true;
        }

        return $this->isDevelopmentEnvironment();
    }

    /**
     * Determine whether manual blocking override is active
     * 
     * @return bool
     */
    public function isForceBlocking(): bool {
        return (bool) $this->settingsManager->get('force_email_blocking', false);
    }
    
    /**
     * Block emails and log attempts
     * 
     * @param array|false $args Email arguments or false if already blocked
     * @return false|array Returns false to block, or modified args to allow
     */
    public function blockEmails($args) {
        // If another filter already blocked this email, respect that
        if ($args === false) {
            return false;
        }
        
        // Ensure we have an array at this point
        if (!is_array($args)) {
            $this->helper->debug('EmailBlocker: Invalid argument type received', [
                'type' => gettype($args),
                'value' => $args
            ]);
            return false;
        }
        
        $subject = $args['subject'] ?? 'No Subject';
        $to = is_array($args['to']) ? implode(', ', $args['to']) : ($args['to'] ?? 'Unknown');
        $message = $args['message'] ?? 'No Message';
        
        // Check if temporarily disabled
        if ($this->operationalData->isEmailBlockingPaused()) {
            $this->helper->debug(sprintf(
                'LGL Email Blocker: ALLOWED (temporarily disabled) - To: %s | Subject: %s',
                $to,
                $subject,
                $message
            ));
            return $args; // Allow email to send
        }
        
        // Check whitelist (for admin testing)
        if ($this->isWhitelisted($to)) {
            $this->helper->debug(sprintf(
                'LGL Email Blocker: ALLOWED (whitelisted) - To: %s | Subject: %s',
                $to,
                $subject
            ));
            return $args; // Allow email to send
        }
        
        // Get blocking level and determine if this email should be blocked
        $blockingLevel = $this->getBlockingLevel();
        $emailType = $this->identifyEmailType($args);
        $shouldBlock = $this->shouldBlockEmail($args, $blockingLevel);
        
        // Debug: Log detection details
        $this->helper->debug(sprintf(
            'LGL Email Blocker: Evaluation - To: %s | Subject: %s | Detected Type: %s | Level: %s | Should Block: %s',
            $to,
            $subject,
            $emailType,
            $blockingLevel,
            $shouldBlock ? 'YES' : 'NO'
        ));
        
        if (!$shouldBlock) {
            $this->helper->debug(sprintf(
                'LGL Email Blocker: ALLOWED (level: %s, type: %s) - To: %s | Subject: %s',
                $blockingLevel,
                $emailType,
                $to,
                $subject
            ));
            return $args; // Allow email to send
        }
        
        // Log the blocked email attempt
        $this->helper->debug(sprintf(
            'LGL Email Blocker: BLOCKED email - To: %s | Subject: %s | Type: %s | Level: %s | Environment: %s | Mode: %s',
            $to,
            $subject,
            $emailType,
            $blockingLevel,
            $this->getEnvironmentInfo(),
            $this->isForceBlocking() ? 'manual_override' : 'environment'
        ));
        
        // Store blocked email for admin review
        $this->operationalData->addBlockedEmail([
            'to' => $args['to'] ?? 'Unknown',
            'subject' => $args['subject'] ?? 'No Subject',
            'message_preview' => substr(strip_tags($args['message'] ?? ''), 0, 200),
            'headers' => $args['headers'] ?? [],
            'email_type' => $emailType,
            'blocking_level' => $blockingLevel,
        ]);
        
        // Return false to prevent email sending
        return false;
    }
    
    /**
     * Determine if an email should be blocked based on blocking level
     * 
     * @param array $args Email arguments
     * @param string $blockingLevel Current blocking level
     * @return bool True if email should be blocked
     */
    private function shouldBlockEmail(array $args, string $blockingLevel): bool {
        // CRITICAL: Always block cron emails regardless of blocking level
        // (User requirement: "I never want to send is any WP Cron related emails")
        if ($this->isCronEmail($args)) {
            return true;
        }
        
        switch ($blockingLevel) {
            case self::LEVEL_BLOCK_ALL:
                // Block everything (cron already handled above)
                return true;
                
            case self::LEVEL_WOOCOMMERCE_ALLOWED:
                // Allow WooCommerce emails, block everything else (cron already handled above)
                return !$this->isWooCommerceEmail($args);
                
            case self::LEVEL_CRON_ONLY:
                // Only block cron-related emails (already handled above, so this shouldn't be reached)
                // But keep for completeness - if we get here, it's not a cron email, so allow it
                return false;
                
            default:
                // Default to blocking all for safety
                return true;
        }
    }
    
    /**
     * Identify the type of email
     * 
     * @param array $args Email arguments
     * @return string Email type identifier
     */
    private function identifyEmailType(array $args): string {
        if ($this->isCronEmail($args)) {
            return 'cron';
        }
        
        if ($this->isWooCommerceEmail($args)) {
            return 'woocommerce';
        }
        
        return 'other';
    }
    
    /**
     * Check if email is from WP Cron
     * 
     * @param array $args Email arguments
     * @return bool True if email is from cron
     */
    private function isCronEmail(array $args): bool {
        // Check if we're in a cron context
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }
        
        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }
        
        // Check backtrace for cron-related hooks
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($backtrace as $frame) {
            // Check for cron hook names
            if (isset($frame['function'])) {
                $function = $frame['function'];
                // Common cron hook patterns
                if (strpos($function, 'cron') !== false || 
                    strpos($function, 'schedule') !== false ||
                    strpos($function, 'wp_schedule') !== false) {
                    return true;
                }
            }
            
            // Check for scheduled hook names in action/hook context
            if (isset($frame['args'][0]) && is_string($frame['args'][0])) {
                $hook = $frame['args'][0];
                // Check for known cron hooks
                $cronHooks = [
                    'lgl_send_daily_order_summary',
                    'woocommerce_scheduled_subscription_payment',
                    'woocommerce_scheduled_subscription_trial_end',
                    'woocommerce_scheduled_subscription_end_of_prepaid_term',
                    'woocommerce_scheduled_subscription_expiration',
                    'woocommerce_scheduled_subscription_payment_retry',
                ];
                
                foreach ($cronHooks as $cronHook) {
                    if (strpos($hook, $cronHook) !== false) {
                        return true;
                    }
                }
            }
        }
        
        // Check subject/content for cron-related indicators
        $subject = strtolower($args['subject'] ?? '');
        $message = strtolower($args['message'] ?? '');
        
        $cronIndicators = [
            'daily order summary',
            'scheduled',
            'automated',
            'cron',
            'wp-cron',
        ];
        
        foreach ($cronIndicators as $indicator) {
            if (strpos($subject, $indicator) !== false || strpos($message, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if email is from WooCommerce
     * 
     * @param array $args Email arguments
     * @return bool True if email is from WooCommerce
     */
    private function isWooCommerceEmail(array $args): bool {
        // WooCommerce must be active
        if (!class_exists('WooCommerce') || !function_exists('WC')) {
            return false;
        }
        
        // Check headers first (most reliable indicator)
        $headers = $args['headers'] ?? [];
        if (is_array($headers)) {
            foreach ($headers as $header) {
                if (is_string($header)) {
                    $headerLower = strtolower($header);
                    // Check for WooCommerce-specific headers
                    if (strpos($headerLower, 'x-wc-email') !== false ||
                        strpos($headerLower, 'woocommerce') !== false ||
                        strpos($headerLower, 'wc-') !== false ||
                        preg_match('/x-wc-[a-z-]+:/i', $header)) {
                        return true;
                    }
                }
            }
        }
        
        // Check backtrace for WooCommerce email classes
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);
        foreach ($backtrace as $frame) {
            // Check for WooCommerce email classes
            if (isset($frame['class'])) {
                $class = $frame['class'];
                if (strpos($class, 'WC_Email') !== false || 
                    strpos($class, 'WooCommerce') !== false) {
                    return true;
                }
            }
            
            // Check for WooCommerce email hooks
            if (isset($frame['function'])) {
                $function = $frame['function'];
                if (strpos($function, 'woocommerce_email') !== false ||
                    strpos($function, 'wc_email') !== false) {
                    return true;
                }
            }
        }
        
        // Check subject for WooCommerce order patterns (but exclude cron emails)
        $subject = strtolower($args['subject'] ?? '');
        
        // First check if it's a cron email - if so, don't treat as WooCommerce
        if ($this->isCronEmail($args)) {
            return false;
        }
        
        // WooCommerce-specific subject patterns
        $wooPatterns = [
            'your order',
            'order confirmation',
            'order #',
            'invoice for order',
            'receipt for order',
            'subscription renewal',
            'subscription payment',
            'membership confirmation',
            'membership renewal',
        ];
        
        foreach ($wooPatterns as $pattern) {
            if (strpos($subject, $pattern) !== false) {
                return true;
            }
        }
        
        // More general patterns (but only if not cron)
        $generalPatterns = [
            'order',
            'invoice',
            'receipt',
            'subscription',
            'membership',
        ];
        
        foreach ($generalPatterns as $pattern) {
            if (strpos($subject, $pattern) !== false) {
                // Additional context checks
                $message = strtolower($args['message'] ?? '');
                if (strpos($message, 'woocommerce') !== false ||
                    strpos($message, 'order number') !== false ||
                    strpos($message, 'order total') !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if email address is whitelisted
     * 
     * @param string $email Email address to check
     * @return bool True if whitelisted
     */
    private function isWhitelisted(string $email): bool {
        // Always allow admin email
        $admin_email = get_option('admin_email');
        if ($email === $admin_email) {
            return true;
        }
        
        // Check custom whitelist from settings
        $whitelist = $this->settingsManager->get('email_whitelist', []);
        return in_array($email, $whitelist, true);
    }
    
    /**
     * Get environment information
     * 
     * @return string Environment description
     */
    private function getEnvironmentInfo(): string {
        $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
        $debug_status = defined('WP_DEBUG') && WP_DEBUG ? 'DEBUG_ON' : 'DEBUG_OFF';
        
        return sprintf('%s (%s)', $host, $debug_status);
    }
    
    /**
     * Show admin notice about email blocking
     */
    public function showEmailBlockingNotice(): void {
        $this->helper->debug('EmailBlocker: showEmailBlockingNotice() called');
        
        if (!$this->isBlockingEnabled()) {
            $this->helper->debug('EmailBlocker: Notice skipped - blocking not enabled');
            return;
        }

        if (!current_user_can('manage_options')) {
            $this->helper->debug('EmailBlocker: Notice skipped - user lacks manage_options capability');
            return;
        }
        
        // Skip notice on the Email Blocking Settings page (it has its own status section)
        if (isset($_GET['page']) && $_GET['page'] === 'lgl-email-blocking') {
            $this->helper->debug('EmailBlocker: Notice skipped - on Email Blocking Settings page');
            return;
        }
        
        $this->helper->debug('EmailBlocker: Rendering admin notice');
        $blocked_count = $this->operationalData->getBlockedEmailsCount();
        $this->helper->debug('EmailBlocker: Blocked count = ' . $blocked_count);
        
        $blockingLevel = $this->getBlockingLevel();
        $levelDescriptions = [
            self::LEVEL_BLOCK_ALL => 'All emails',
            self::LEVEL_WOOCOMMERCE_ALLOWED => 'Non-WooCommerce emails',
            self::LEVEL_CRON_ONLY => 'WP Cron emails only',
        ];
        
        $levelDescription = $levelDescriptions[$blockingLevel] ?? 'Emails';
        
        // Make notice persistent (not dismissible) since this is a critical warning
        // Add unique ID and inline styles to prevent dismissal
        ?>
        <div id="lgl-email-blocker-notice" class="notice notice-warning" style="border-left-color: #d63638; border-left-width: 4px; display: block !important; visibility: visible !important; opacity: 1 !important;">
            <p><strong>🚫 LGL Email Blocker Active</strong></p>
            <p>
                Blocking: <strong><?php echo esc_html($levelDescription); ?></strong>
                (<?php echo $blocked_count; ?> blocked since activation) 
                <?php if ($this->isForceBlocking()): ?>
                    <span style="color:#d63638;"><strong>Manual override is enabled.</strong></span> 
                <?php else: ?>
                    Development environment detected. 
                <?php endif; ?>
                <a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>" class="button button-small">Manage Email Blocking</a>
            </p>
        </div>
        <script type="text/javascript">
        (function() {
            // Store the notice HTML for re-injection if needed
            var noticeHTML = document.getElementById('lgl-email-blocker-notice');
            if (!noticeHTML) return;
            
            var originalHTML = noticeHTML.outerHTML;
            
            // Function to ensure notice is visible
            function ensureNoticeVisible() {
                var notice = document.getElementById('lgl-email-blocker-notice');
                if (!notice) {
                    // Notice was removed - re-inject it
                    var container = document.querySelector('.wrap') || document.querySelector('#wpbody-content');
                    if (container && container.parentNode) {
                        var tempDiv = document.createElement('div');
                        tempDiv.innerHTML = originalHTML;
                        var firstChild = container.parentNode.querySelector('.wrap, #wpbody-content');
                        if (firstChild) {
                            firstChild.parentNode.insertBefore(tempDiv.firstChild, firstChild);
                            console.log('LGL Email Blocker notice re-injected');
                        }
                    }
                } else {
                    // Notice exists - ensure it's visible
                    var dismissBtn = notice.querySelector('.notice-dismiss');
                    if (dismissBtn) {
                        dismissBtn.remove();
                    }
                    notice.style.display = 'block';
                    notice.style.visibility = 'visible';
                    notice.style.opacity = '1';
                }
            }
            
            // Initial setup
            ensureNoticeVisible();
            console.log('LGL Email Blocker notice rendered and protected from dismissal');
            
            // Monitor for removal attempts using MutationObserver
            if (window.MutationObserver) {
                var observer = new MutationObserver(function(mutations) {
                    ensureNoticeVisible();
                });
                
                // Watch the entire body for changes
                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
            
            // Also check periodically as backup
            setInterval(ensureNoticeVisible, 1000);
        })();
        </script>
        <?php
    }
    
    /**
     * Get blocked email statistics
     * 
     * @return array Statistics about blocked emails
     */
    public function getStats(): array {
        return [
            'total_blocked' => $this->operationalData->getBlockedEmailsCount(),
            'is_blocking' => $this->isBlockingEnabled(),
            'is_forced' => $this->isForceBlocking(),
            'blocking_level' => $this->getBlockingLevel(),
            'environment' => $this->getEnvironmentInfo(),
            'recent_blocks' => array_slice(array_reverse($this->operationalData->getBlockedEmails()), 0, 5)
        ];
    }
    
    /**
     * Get current blocking status
     * 
     * @return array Status information
     */
    public function getBlockingStatus(): array {
        return [
            'is_development' => $this->isDevelopmentEnvironment(),
            'is_temporarily_disabled' => $this->operationalData->isEmailBlockingPaused(),
            'is_actively_blocking' => $this->isBlockingEnabled() && !$this->operationalData->isEmailBlockingPaused(),
            'is_force_blocking' => $this->isForceBlocking(),
            'blocking_level' => $this->getBlockingLevel(),
            'environment_info' => $this->getEnvironmentInfo()
        ];
    }
}
