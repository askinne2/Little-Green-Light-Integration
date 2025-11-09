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
        if ($this->isBlockingEnabled()) {
            add_filter('wp_mail', [$this, 'blockEmails'], 999);
            add_action('admin_notices', [$this, 'showEmailBlockingNotice']);
            
            $mode = $this->isForceBlocking() ? 'MANUAL OVERRIDE ENABLED' : 'Development environment detected';
            $this->helper->debug('LGL Email Blocker: ACTIVE - ' . $mode);
        } else {
            $this->helper->debug('LGL Email Blocker: INACTIVE - Manual override disabled and environment not flagged');
        }
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
     * @param array $args Email arguments
     * @return false|array Returns false to block, or modified args to allow
     */
    public function blockEmails(array $args) {
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
                $subject,
                $message
            ));
            return $args; // Allow email to send
        }
        
        // Log the blocked email attempt
        $this->helper->debug(sprintf(
            'LGL Email Blocker: BLOCKED email - To: %s | Subject: %s | Environment: %s | Mode: %s',
            $to,
            $subject,
            $this->getEnvironmentInfo(),
            $this->isForceBlocking() ? 'manual_override' : 'environment'
        ));
        
        // Store blocked email for admin review
        $this->operationalData->addBlockedEmail([
            'to' => $args['to'] ?? 'Unknown',
            'subject' => $args['subject'] ?? 'No Subject',
            'message_preview' => substr(strip_tags($args['message'] ?? ''), 0, 200),
            'headers' => $args['headers'] ?? [],
        ]);
        
        // Return false to prevent email sending
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
        
        // Make notice persistent (not dismissible) since this is a critical warning
        // Add unique ID and inline styles to prevent dismissal
        ?>
        <div id="lgl-email-blocker-notice" class="notice notice-warning" style="border-left-color: #d63638; border-left-width: 4px; display: block !important; visibility: visible !important; opacity: 1 !important;">
            <p><strong>🚫 LGL Email Blocker Active</strong></p>
            <p>
                All outgoing emails are being blocked. 
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
            'environment_info' => $this->getEnvironmentInfo()
        ];
    }
}
