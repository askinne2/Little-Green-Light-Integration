<?php
/**
 * Email Blocker for Development Environments
 * 
 * ⚠️ DISABLED MODULE ⚠️
 * 
 * This module has been DISABLED due to conflicts with WPSMTP Pro plugin.
 * Email blocking is now handled by WPSMTP Pro's email blocking module.
 * 
 * To configure email blocking:
 * - Go to: WordPress Admin → WP Mail SMTP → Settings → Email Controls
 * - Use WPSMTP Pro's built-in email blocking features
 * 
 * This file is kept for reference only and is NOT loaded in the plugin architecture.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 * @deprecated Disabled - use WPSMTP Pro email blocking instead
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
    const LEVEL_NONE = 'none';
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
        'development',
        'tempurl.host',  // WPMU Dev staging URLs
        'tempurl',       // WPMU Dev staging URLs (shorter variant)
    ];
    
    /**
     * Static flag to track if initialization has been logged
     * Prevents duplicate log entries when init() is called multiple times
     * 
     * @var bool
     */
    private static bool $initLogged = false;
    
    /**
     * Transient key for tracking last logged blocking status
     */
    const STATUS_LOG_TRANSIENT = 'lgl_email_blocker_last_status';
    
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
        // Get all relevant settings and states
        $blockingLevel = $this->getBlockingLevel();
        $isEnabled = $this->isBlockingEnabled();
        $isForceBlocking = $this->isForceBlocking();
        $isDevEnv = $this->isDevelopmentEnvironment();
        $envDetectionDisabled = $this->isEnvironmentDetectionDisabled();
        $dropdownLevel = $this->settingsManager->get('email_blocking_level', self::LEVEL_BLOCK_ALL);
        
        // Build status signature to detect changes
        $statusSignature = sprintf('%s:%d:%d:%d', $blockingLevel, $isEnabled ? 1 : 0, $isForceBlocking ? 1 : 0, $envDetectionDisabled ? 1 : 0);
        
        // Only log initialization and status changes when blocking is actually enabled
        // This reduces log noise when blocking is disabled
        if ($blockingLevel && $blockingLevel !== 'none') {
            // Only log status changes at INFO level (when blocking state actually changes)
            // Use transient to persist status between requests
            $lastLoggedStatus = get_transient(self::STATUS_LOG_TRANSIENT);
            if ($lastLoggedStatus !== $statusSignature) {
                $mode = $isForceBlocking ? 'MANUAL OVERRIDE ENABLED' : ($envDetectionDisabled ? 'Environment detection disabled' : 'Development environment detected');
                $this->helper->info('LGL Email Blocker: ACTIVE - ' . $mode . ' (Level: ' . $blockingLevel . ')', [
                    'dropdown_selection' => $dropdownLevel,
                    'force_blocking' => $isForceBlocking,
                    'env_detection_disabled' => $envDetectionDisabled,
                    'dev_env_detected' => $isDevEnv,
                ]);
                // Store status for 1 hour (long enough to prevent repeated logs, short enough to catch real changes)
                set_transient(self::STATUS_LOG_TRANSIENT, $statusSignature, HOUR_IN_SECONDS);
            }
        } else {
            // Blocking is disabled - only log initialization once per request, and only in debug mode
            $logLevel = $this->settingsManager->get('log_level', 'info');
            if (!self::$initLogged && $logLevel === 'debug') {
                $this->helper->debug('LGL Email Blocker: Disabled (blocking not active)', [
                    'blocking_level' => $blockingLevel,
                ]);
                self::$initLogged = true;
            }
        }
        
        // Register filters if blocking is enabled (always check, not just on first init)
        // CRITICAL: Don't register hooks during settings save to prevent hangs
        if (isset($_POST['save_email_blocking']) || isset($_POST['lgl_email_blocking_settings'])) {
            return;
        }
        
        // Always check if hooks should be registered or removed based on current blocking level
        $hasWpMailFilter = has_filter('wp_mail', [$this, 'blockEmails']);
        // Note: has_action() is an alias for has_filter() in WordPress
        $hasPhpmailerAction = has_action('phpmailer_init', [$this, 'interceptPhpmailer']);
        
        if ($blockingLevel && $blockingLevel !== 'none') {
            // Blocking is enabled - register hooks if not already registered
            if (!$hasWpMailFilter) {
                // Register wp_mail filter with highest priority to catch all emails
                add_filter('wp_mail', [$this, 'blockEmails'], 999);
                
                // Also hook into PHPMailer to catch emails that might bypass wp_mail
                // Use priority 9999 (AFTER Branda's SMTP init at 999) to prevent sending
                // This allows SMTP plugins to configure first, then we block if needed
                add_action('phpmailer_init', [$this, 'interceptPhpmailer'], 9999);
                
                add_action('admin_notices', [$this, 'showEmailBlockingNotice']);
            }
        } else {
            // Blocking is disabled - remove hooks if they're registered (silently, no logging)
            if ($hasWpMailFilter) {
                remove_filter('wp_mail', [$this, 'blockEmails'], 999);
            }
            if ($hasPhpmailerAction) {
                remove_action('phpmailer_init', [$this, 'interceptPhpmailer'], 9999);
            }
            
            // Only verify and warn if hooks weren't removed (this is a real problem)
            $stillHasWpMail = has_filter('wp_mail', [$this, 'blockEmails']);
            $stillHasPhpmailer = has_action('phpmailer_init', [$this, 'interceptPhpmailer']);
            
            if ($stillHasWpMail || $stillHasPhpmailer) {
                $this->helper->warning('LGL Email Blocker: WARNING - Hooks still registered after removal attempt', [
                    'wp_mail_still_registered' => $stillHasWpMail,
                    'phpmailer_still_registered' => $stillHasPhpmailer,
                    'blocking_level' => $blockingLevel,
                ]);
            }
        }
        
        // Fix invalid SMTP configs (runs on all environments)
        // This prevents emails failing when SMTP is configured but server doesn't exist
        if (!has_action('phpmailer_init', [$this, 'fixInvalidSMTPConfig'])) {
            add_action('phpmailer_init', [$this, 'fixInvalidSMTPConfig'], 2); // Priority 2 (before Mailpit at 1, but after other plugins)
        }
        
        // Configure Mailpit for local development (if no SMTP is configured)
        // This ensures emails work in local environments without Branda
        if (!has_action('phpmailer_init', [$this, 'configureMailpit'])) {
            add_action('phpmailer_init', [$this, 'configureMailpit'], 1); // Priority 1 (before other hooks)
        }
        
        // DIAGNOSTIC: Register diagnostic hooks only in debug mode AND when blocking is enabled
        // This helps debug hangs and email flow issues without cluttering production logs
        // NOTE: Only enable in debug mode, not just dev environments (QA/staging shouldn't have verbose logs)
        $logLevel = $this->settingsManager->get('log_level', 'info');
        $isDebugMode = ($logLevel === 'debug');
        if ($isDebugMode && $blockingLevel && $blockingLevel !== 'none') {
            $hasDiagnosticPhpmailer = has_action('phpmailer_init', [$this, 'diagnosticPhpmailerLog']);
            $hasDiagnosticWpMail = has_filter('wp_mail', [$this, 'diagnosticWpMailLog']);
            
            if (!$hasDiagnosticPhpmailer) {
                add_action('phpmailer_init', [$this, 'diagnosticPhpmailerLog'], 10); // Priority 10 (AFTER Mailpit config at 1)
            }
            if (!$hasDiagnosticWpMail) {
                add_filter('wp_mail', [$this, 'diagnosticWpMailLog'], 1); // Priority 1 (before blocking at 999)
            }
        }
    }
    
    /**
     * Fix invalid SMTP configurations that would cause email failures
     * Runs on all environments to prevent emails failing due to bad SMTP config
     */
    public function fixInvalidSMTPConfig($phpmailer): void {
        // Check if SMTP is configured (check both isSMTP() method AND Mailer property)
        // Sometimes Mailer property is set to 'smtp' but isSMTP() hasn't been called yet
        $isSMTPMethod = false;
        $mailerProperty = null;
        
        if (method_exists($phpmailer, 'isSMTP')) {
            $isSMTPMethod = $phpmailer->isSMTP();
        }
        
        if (property_exists($phpmailer, 'Mailer')) {
            $mailerProperty = $phpmailer->Mailer;
        }
        
        // Consider it SMTP if either the method returns true OR the Mailer property is 'smtp'
        $isSMTP = $isSMTPMethod || ($mailerProperty === 'smtp');
        
        // Only log diagnostic info in debug mode if we're actually fixing something
        // (reduces log noise when SMTP is properly configured)
        
        if (!$isSMTP) {
            return; // Not in SMTP mode, nothing to fix
        }
        
        $host = $phpmailer->Host ?? '';
        $port = $phpmailer->Port ?? 25;
        $isLocalhost = (
            $host === 'localhost' ||
            $host === '127.0.0.1' ||
            empty($host)
        );
        
        // Check if we're actually on localhost (where Mailpit might be available)
        $serverHost = $_SERVER['HTTP_HOST'] ?? '';
        $isActuallyLocalhost = (
            strpos($serverHost, 'localhost') !== false ||
            strpos($serverHost, '127.0.0.1') !== false ||
            strpos($serverHost, '.local') !== false ||
            (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '127.0.0.1')
        );
        
        // If SMTP is configured for localhost but we're NOT on localhost, reset to mail() mode
        if ($isLocalhost && !$isActuallyLocalhost) {
            // Store original values for logging
            $originalHost = $host;
            $originalPort = $port;
            $originalMailer = $mailerProperty;
            
            // Reset to mail() mode - localhost SMTP won't work on staging/QA/production
            if (method_exists($phpmailer, 'isMail')) {
                $phpmailer->isMail();
            } else {
                $phpmailer->Mailer = 'mail';
            }
            
            // Clear SMTP-specific properties to ensure clean state
            $phpmailer->Host = '';
            $phpmailer->SMTPAuth = false;
            
            // Log at info level so it's visible (this is important - emails were failing)
            $this->helper->info('LGL Email Blocker: Fixed invalid SMTP config (localhost on non-localhost server)', [
                'server_host' => $serverHost,
                'original_host' => $originalHost,
                'original_port' => $originalPort,
            ]);
        }
    }
    
    /**
     * Configure Mailpit for local development
     * Only applies if no other SMTP is already configured
     */
    public function configureMailpit($phpmailer): void {
        // CRITICAL: Only configure Mailpit on actual localhost, not staging/QA servers
        // Check for localhost/127.0.0.1 specifically, not just dev environment detection
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $isLocalhost = (
            strpos($host, 'localhost') !== false ||
            strpos($host, '127.0.0.1') !== false ||
            strpos($host, '.local') !== false ||
            (isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] === '127.0.0.1')
        );
        
        // Also check if we're in a local development environment AND it's actually localhost
        $isLocal = $isLocalhost && $this->isDevelopmentEnvironment();
        if (!$isLocal) {
            return; // Only configure Mailpit on actual localhost, not staging/QA
        }
        
        // Only configure if SMTP is not already set up
        // Check BEFORE configuring to avoid overriding existing SMTP configs
        $alreadySMTP = method_exists($phpmailer, 'isSMTP') && $phpmailer->isSMTP();
        $hasValidSMTPHost = !empty($phpmailer->Host) && $phpmailer->Host !== 'localhost' && $phpmailer->Host !== '127.0.0.1';
        
        if ($alreadySMTP && $hasValidSMTPHost) {
            // SMTP already configured with a real host (likely by Branda or another plugin)
            $logLevel = $this->settingsManager->get('log_level', 'info');
            if ($logLevel === 'debug') {
                $this->helper->debug('LGL Email Blocker: SMTP already configured, skipping Mailpit', [
                    'existing_host' => $phpmailer->Host,
                    'existing_port' => $phpmailer->Port,
                ]);
            }
            return;
        }
        
        // If SMTP is set but host is localhost/empty, reset to mail() mode for production
        // This prevents trying to use non-existent localhost SMTP servers
        if ($alreadySMTP && ($phpmailer->Host === 'localhost' || $phpmailer->Host === '127.0.0.1' || empty($phpmailer->Host))) {
            // Reset to mail() mode - no SMTP server available
            if (method_exists($phpmailer, 'isMail')) {
                $phpmailer->isMail();
            } else {
                $phpmailer->Mailer = 'mail';
            }
            $logLevel = $this->settingsManager->get('log_level', 'info');
            if ($logLevel === 'debug') {
                $this->helper->debug('LGL Email Blocker: Reset PHPMailer from invalid SMTP config to mail() mode', [
                    'previous_host' => $phpmailer->Host,
                    'previous_port' => $phpmailer->Port,
                ]);
            }
            return; // Don't configure Mailpit, just reset to mail() mode
        }
        
        // Configure Mailpit (LocalWP Mailpit settings)
        // IMPORTANT: Set Mailer property directly to ensure SMTP mode is enabled
        $phpmailer->Mailer = 'smtp';
        $phpmailer->Host = 'localhost';
        $phpmailer->Port = 10092; // LocalWP Mailpit SMTP port (10092 = SMTP, 10091 = Web UI)
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPSecure = false;
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->Timeout = 10;
        
        // Also call isSMTP() to ensure PHPMailer is in SMTP mode
        if (method_exists($phpmailer, 'isSMTP')) {
            $phpmailer->isSMTP();
        }
        
        // Only log in debug mode to avoid cluttering production logs
        $logLevel = $this->settingsManager->get('log_level', 'info');
        if ($logLevel === 'debug') {
            $this->helper->info('LGL Email Blocker: Configured Mailpit for local development', [
                'host' => $phpmailer->Host,
                'port' => $phpmailer->Port,
                'was_already_smtp' => $alreadySMTP,
            ]);
        }
    }
    
    /**
     * Diagnostic: Log all PHPMailer activity (even when blocking is disabled)
     * This helps debug hangs and email flow issues
     */
    public function diagnosticPhpmailerLog($phpmailer): void {
        $toAddresses = $phpmailer->getToAddresses();
        $to = is_array($toAddresses) && !empty($toAddresses)
            ? implode(', ', array_column($toAddresses, 0))
            : 'Unknown';
        $subject = $phpmailer->Subject ?? 'No Subject';
        
        // Check if SMTP is configured (Branda runs at priority 999, Mailpit at priority 1)
        // Use reflection to check Mailer property since isSMTP() might not be reliable
        $isSMTP = false;
        if (method_exists($phpmailer, 'isSMTP')) {
            $isSMTP = $phpmailer->isSMTP();
        } elseif (property_exists($phpmailer, 'Mailer')) {
            $isSMTP = ($phpmailer->Mailer === 'smtp');
        }
        
        $this->helper->debug('LGL Email Blocker: DIAGNOSTIC - PHPMailer initialized', [
            'to' => $to,
            'subject' => $subject,
            'is_smtp' => $isSMTP,
            'mailer_type' => property_exists($phpmailer, 'Mailer') ? $phpmailer->Mailer : 'unknown',
            'smtp_host' => $phpmailer->Host ?? 'N/A',
            'smtp_port' => $phpmailer->Port ?? 'N/A',
            'smtp_auth' => $phpmailer->SMTPAuth ?? false,
            'smtp_secure' => $phpmailer->SMTPSecure ?? 'N/A',
            'from_email' => $phpmailer->From ?? 'N/A',
            'blocking_enabled' => $this->isBlockingEnabled(),
            'blocking_level' => $this->getBlockingLevel(),
        ]);
    }
    
    /**
     * Diagnostic: Log all wp_mail activity (even when blocking is disabled)
     * This helps debug hangs and email flow issues
     */
    public function diagnosticWpMailLog($args) {
        if (is_array($args)) {
            $to = is_array($args['to'] ?? []) ? implode(', ', $args['to']) : ($args['to'] ?? 'Unknown');
            $subject = $args['subject'] ?? 'No Subject';
            
            $this->helper->debug('LGL Email Blocker: DIAGNOSTIC - wp_mail called', [
                'to' => $to,
                'subject' => $subject,
                'message_length' => strlen($args['message'] ?? ''),
                'blocking_enabled' => $this->isBlockingEnabled(),
                'blocking_level' => $this->getBlockingLevel(),
            ]);
        }
        
        return $args; // Always pass through - this is diagnostic only
    }
    
    /**
     * Get current blocking level
     * 
     * @return string Blocking level
     */
    public function getBlockingLevel(): string {
        // Get blocking level from settings, default to 'all' for backward compatibility
        $level = $this->settingsManager->get('email_blocking_level', self::LEVEL_BLOCK_ALL);
        
        // Validate level
        $validLevels = [self::LEVEL_NONE, self::LEVEL_BLOCK_ALL, self::LEVEL_WOOCOMMERCE_ALLOWED, self::LEVEL_CRON_ONLY];
        if (!in_array($level, $validLevels, true)) {
            return self::LEVEL_BLOCK_ALL;
        }
        
        // If user explicitly set to 'none', return 'none' (overrides environment detection)
        if ($level === self::LEVEL_NONE) {
            return self::LEVEL_NONE;
        }
        
        // If blocking is not enabled (no force blocking and not dev environment), return 'none'
        if (!$this->isBlockingEnabled()) {
            return 'none';
        }
        
        // Otherwise return the selected level
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
     * IMPORTANT: This does NOT check for LEVEL_NONE - that's handled in getBlockingLevel()
     * This method only checks if blocking should be enabled based on force blocking or environment.
     * 
     * @return bool
     */
    public function isBlockingEnabled(): bool {
        // Force blocking overrides everything
        if ($this->isForceBlocking()) {
            return true;
        }

        // If environment detection is disabled, only force blocking enables blocking
        if ($this->isEnvironmentDetectionDisabled()) {
            return false;
        }

        // Otherwise, check environment
        return $this->isDevelopmentEnvironment();
    }
    
    /**
     * Check if environment detection is disabled
     * 
     * @return bool True if environment detection is disabled
     */
    public function isEnvironmentDetectionDisabled(): bool {
        return (bool) $this->settingsManager->get('disable_email_blocking_env_detection', false);
    }
    
    /**
     * Get decision path for debugging
     * 
     * @return string Human-readable decision path
     */
    private function getDecisionPath(): string {
        $dropdownLevel = $this->settingsManager->get('email_blocking_level', self::LEVEL_BLOCK_ALL);
        $isForceBlocking = $this->isForceBlocking();
        $envDetectionDisabled = $this->isEnvironmentDetectionDisabled();
        $isDevEnv = $this->isDevelopmentEnvironment();
        
        $path = [];
        $path[] = "Dropdown: {$dropdownLevel}";
        
        if ($dropdownLevel === self::LEVEL_NONE) {
            $path[] = "→ Blocking DISABLED (dropdown override)";
            return implode(' | ', $path);
        }
        
        if ($isForceBlocking) {
            $path[] = "→ Force blocking ENABLED";
            return implode(' | ', $path);
        }
        
        if ($envDetectionDisabled) {
            $path[] = "→ Environment detection DISABLED";
            $path[] = "→ Blocking DISABLED (no force, no env detection)";
            return implode(' | ', $path);
        }
        
        if ($isDevEnv) {
            $path[] = "→ Dev environment DETECTED";
            $path[] = "→ Blocking ENABLED";
        } else {
            $path[] = "→ Production environment";
            $path[] = "→ Blocking DISABLED (production, no force)";
        }
        
        return implode(' | ', $path);
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
        // VERBOSE LOGGING: Log every email that comes through wp_mail filter
        $this->helper->debug('LGL Email Blocker: wp_mail filter called', [
            'args_type' => gettype($args),
            'is_array' => is_array($args),
            'is_false' => ($args === false),
            'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX,
            'is_admin' => is_admin(),
            'post_keys' => isset($_POST) ? array_keys($_POST) : [],
        ]);
        
        // CRITICAL: Double-check if blocking is actually enabled before processing
        // This prevents blocking when hooks weren't properly removed
        $blockingLevel = $this->getBlockingLevel();
        $isEnabled = $this->isBlockingEnabled();
        
        if ($blockingLevel === self::LEVEL_NONE || !$isEnabled) {
            $this->helper->debug('LGL Email Blocker: Blocking disabled, allowing email through wp_mail', [
                'blocking_level' => $blockingLevel,
                'is_enabled' => $isEnabled,
            ]);
            return $args; // Blocking disabled, allow email
        }
        
        // CRITICAL: Skip processing during settings save operations
        // Check transient flag set by EmailBlockingSettingsPage
        if (get_transient('lgl_email_blocking_saving')) {
            $this->helper->debug('LGL Email Blocker: Settings save in progress, allowing email');
            return $args; // Allow emails during settings save to prevent hangs
        }
        
        // CRITICAL: Skip processing during admin POST requests (settings saves, etc.)
        // This prevents hangs when saving email blocking settings
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $this->helper->debug('LGL Email Blocker: AJAX request, allowing email');
            return $args;
        }
        
        if (isset($_POST['save_email_blocking']) || isset($_POST['lgl_email_blocking_settings'])) {
            $this->helper->debug('LGL Email Blocker: Email blocking settings save detected, allowing email');
            return $args; // Allow emails during settings save to prevent hangs
        }
        
        // If another filter already blocked this email, respect that
        if ($args === false) {
            $this->helper->debug('LGL Email Blocker: Email already blocked by another filter');
            return false;
        }
        
        // Ensure we have an array at this point
        if (!is_array($args)) {
            // Only log errors for invalid arguments
            $this->helper->error('EmailBlocker: Invalid argument type received', [
                'type' => gettype($args)
            ]);
            return false;
        }
        
        $subject = $args['subject'] ?? 'No Subject';
        $to = is_array($args['to']) ? implode(', ', $args['to']) : ($args['to'] ?? 'Unknown');
        $message = $args['message'] ?? 'No Message';
        
        // VERBOSE LOGGING: Log email details
        $this->helper->debug('LGL Email Blocker: Processing email via wp_mail', [
            'to' => $to,
            'subject' => $subject,
            'message_length' => strlen($message),
            'headers_count' => is_array($args['headers'] ?? []) ? count($args['headers']) : 0,
        ]);
        
        // Check if temporarily disabled
        if ($this->operationalData->isEmailBlockingPaused()) {
            $this->helper->debug('LGL Email Blocker: Blocking temporarily paused, allowing email', [
                'to' => $to,
                'subject' => $subject,
            ]);
            return $args; // Allow email to send
        }
        
        // Check whitelist (for admin testing)
        // When force blocking is enabled, admin email whitelist is bypassed by default
        // This ensures ALL emails are blocked when force blocking is active
        $isWhitelisted = $this->isWhitelisted($to);
        $shouldRespectWhitelist = !$this->isForceBlocking(); // Don't respect whitelist when force blocking
        
        if ($isWhitelisted && $shouldRespectWhitelist) {
            $this->helper->debug('LGL Email Blocker: Email whitelisted, allowing through', [
                'to' => $to,
                'subject' => $subject,
                'is_admin_email' => ($to === get_option('admin_email')),
            ]);
            return $args; // Allow email to send
        }
        
        // Get blocking level and determine if this email should be blocked
        $blockingLevel = $this->getBlockingLevel();
        $emailType = $this->identifyEmailType($args);
        $shouldBlock = $this->shouldBlockEmail($args, $blockingLevel);
        
        $this->helper->debug('LGL Email Blocker: Email blocking decision', [
            'to' => $to,
            'subject' => $subject,
            'email_type' => $emailType,
            'blocking_level' => $blockingLevel,
            'should_block' => $shouldBlock,
            'is_whitelisted' => $isWhitelisted,
            'respect_whitelist' => $shouldRespectWhitelist,
        ]);
        
        if (!$shouldBlock) {
            $this->helper->debug('LGL Email Blocker: Email allowed (not blocked)', [
                'to' => $to,
                'subject' => $subject,
                'email_type' => $emailType,
            ]);
            return $args; // Allow email to send
        }
        
        // Only log blocked emails (not every evaluation) - use info level to reduce verbosity
        $this->helper->info(sprintf(
            'LGL Email Blocker: BLOCKED - To: %s | Subject: %s | Type: %s',
            $to,
            $subject,
            $emailType
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
     * Intercept PHPMailer to block emails that might bypass wp_mail filter
     * This catches WooCommerce emails and other emails sent directly via PHPMailer
     * 
     * IMPORTANT: This runs at priority 9999 (AFTER Branda's SMTP init at 999) to prevent
     * SMTP connection attempts when emails are blocked. This allows SMTP plugins to
     * configure first, then we block if needed.
     * 
     * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer PHPMailer instance
     * @return void
     */
    public function interceptPhpmailer($phpmailer): void {
        // VERBOSE LOGGING: Log every PHPMailer initialization at the very start
        $toAddresses = $phpmailer->getToAddresses();
        $to = is_array($toAddresses) && !empty($toAddresses)
            ? implode(', ', array_column($toAddresses, 0))
            : 'Unknown';
        $subject = $phpmailer->Subject ?? 'No Subject';
        
        $this->helper->debug('LGL Email Blocker: phpmailer_init hook called', [
            'to' => $to,
            'subject' => $subject,
            'recipient_count' => is_array($toAddresses) ? count($toAddresses) : 0,
            'is_smtp' => method_exists($phpmailer, 'isSMTP') && $phpmailer->isSMTP(),
            'smtp_host' => $phpmailer->Host ?? 'N/A',
            'already_blocked_flag' => isset($phpmailer->lgl_blocked),
            'doing_ajax' => defined('DOING_AJAX') && DOING_AJAX,
            'is_admin' => is_admin(),
            'post_keys' => isset($_POST) ? array_keys($_POST) : [],
        ]);
        
        // CRITICAL: Double-check if blocking is actually enabled before processing
        // This prevents blocking when hooks weren't properly removed
        $blockingLevel = $this->getBlockingLevel();
        $isEnabled = $this->isBlockingEnabled();
        
        if ($blockingLevel === self::LEVEL_NONE || !$isEnabled) {
            $this->helper->debug('LGL Email Blocker: Blocking disabled, allowing email through PHPMailer', [
                'to' => $to,
                'subject' => $subject,
                'blocking_level' => $blockingLevel,
                'is_enabled' => $isEnabled,
            ]);
            return; // Blocking disabled, allow email
        }
        
        // CRITICAL: Skip processing during settings save operations
        // Check transient flag set by EmailBlockingSettingsPage
        if (get_transient('lgl_email_blocking_saving')) {
            $this->helper->debug('LGL Email Blocker: Settings save in progress, allowing email through PHPMailer');
            return;
        }
        
        // CRITICAL: Skip processing during admin POST requests (settings saves, etc.)
        // This prevents hangs when saving email blocking settings
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $this->helper->debug('LGL Email Blocker: AJAX request, allowing email through PHPMailer');
            return;
        }
        
        // Skip during settings save operations (check POST data)
        if (isset($_POST['save_email_blocking']) || isset($_POST['lgl_email_blocking_settings'])) {
            $this->helper->debug('LGL Email Blocker: Email blocking settings save detected, allowing email through PHPMailer');
            return;
        }
        
        // Skip if we're in admin and this might be a settings save
        if (is_admin() && isset($_POST) && !empty($_POST)) {
            // Check if this is likely a settings save (but allow other admin POSTs)
            $post_keys = array_keys($_POST);
            foreach ($post_keys as $key) {
                if (strpos($key, 'email_blocking') !== false || strpos($key, 'lgl_email') !== false) {
                    $this->helper->debug('LGL Email Blocker: Likely settings save detected in POST data, allowing email');
                    return;
                }
            }
        }
        
        // Check if we've already processed this PHPMailer instance (prevent duplicate processing)
        if (isset($phpmailer->lgl_blocked)) {
            $this->helper->debug('LGL Email Blocker: PHPMailer instance already processed, skipping', [
                'to' => $to,
                'subject' => $subject,
            ]);
            return;
        }
        
        // If no recipients, skip (already blocked or invalid)
        if (empty($toAddresses) || $to === 'Unknown') {
            $this->helper->debug('LGL Email Blocker: No recipients found in PHPMailer, skipping', [
                'to_addresses' => $toAddresses,
            ]);
            return;
        }
        
        // VERBOSE LOGGING: Log PHPMailer email details before processing
        $this->helper->debug('LGL Email Blocker: Processing email via PHPMailer', [
            'to' => $to,
            'subject' => $subject,
            'body_length' => strlen($phpmailer->Body ?? ''),
            'is_smtp' => method_exists($phpmailer, 'isSMTP') && $phpmailer->isSMTP(),
            'smtp_host' => $phpmailer->Host ?? 'N/A',
        ]);
        
        // Check if temporarily disabled
        if ($this->operationalData->isEmailBlockingPaused()) {
            $this->helper->debug('LGL Email Blocker: Blocking temporarily paused, allowing email through PHPMailer', [
                'to' => $to,
                'subject' => $subject,
            ]);
            return; // Allow email
        }
        
        // Check whitelist (but respect force blocking override)
        $shouldRespectWhitelist = !$this->isForceBlocking() || $this->settingsManager->get('email_blocking_respect_whitelist', true);
        $isWhitelisted = $this->isWhitelisted($to);
        
        if ($shouldRespectWhitelist && $isWhitelisted) {
            $this->helper->debug('LGL Email Blocker: Email whitelisted, allowing through PHPMailer', [
                'to' => $to,
                'subject' => $subject,
                'is_admin_email' => ($to === get_option('admin_email')),
            ]);
            return; // Allow email
        }
        
        // Build args array for shouldBlockEmail check
        $args = [
            'to' => $to,
            'subject' => $subject,
            'message' => $phpmailer->Body ?? '',
            'headers' => $phpmailer->getCustomHeaders() ?? [],
        ];
        
        $blockingLevel = $this->getBlockingLevel();
        $emailType = $this->identifyEmailType($args);
        $shouldBlock = $this->shouldBlockEmail($args, $blockingLevel);
        
        $this->helper->debug('LGL Email Blocker: PHPMailer blocking decision', [
            'to' => $to,
            'subject' => $subject,
            'email_type' => $emailType,
            'blocking_level' => $blockingLevel,
            'should_block' => $shouldBlock,
            'is_whitelisted' => $isWhitelisted,
            'respect_whitelist' => $shouldRespectWhitelist,
        ]);
        
        if ($shouldBlock) {
            // Mark as blocked to prevent duplicate processing
            $phpmailer->lgl_blocked = true;
            
            // Log the blocked email
            $this->helper->info(sprintf(
                'LGL Email Blocker: BLOCKED (PHPMailer) - To: %s | Subject: %s | Type: %s',
                $to,
                $subject,
                $this->identifyEmailType($args)
            ));
            
            // Store blocked email (logging happens in operationalData)
            $this->operationalData->addBlockedEmail([
                'to' => $to,
                'subject' => $subject,
                'message_preview' => substr(strip_tags($phpmailer->Body ?? ''), 0, 200),
                'headers' => $phpmailer->getCustomHeaders() ?? [],
                'email_type' => $this->identifyEmailType($args),
                'blocking_level' => $blockingLevel,
            ]);
            
            // CRITICAL: Prevent SMTP connection by disabling SMTP mode
            // This prevents Branda from trying to connect when there are no recipients
            $wasSMTP = method_exists($phpmailer, 'isSMTP') && $phpmailer->isSMTP();
            if ($wasSMTP) {
                // Switch back to mail() to prevent SMTP connection attempt
                $phpmailer->isMail();
                $this->helper->debug('LGL Email Blocker: Switched PHPMailer from SMTP to mail() mode to prevent connection', [
                    'to' => $to,
                    'subject' => $subject,
                    'smtp_host' => $phpmailer->Host ?? 'N/A',
                ]);
            }
            
            // Clear all recipients to prevent sending
            $phpmailer->clearAddresses();
            $phpmailer->clearCCs();
            $phpmailer->clearBCCs();
            $phpmailer->clearReplyTos();
            
            // Clear body and subject to prevent sending empty emails
            $phpmailer->Body = '';
            $phpmailer->AltBody = '';
            $phpmailer->Subject = '';
            
            // Set an error to prevent PHPMailer from attempting to send
            if (method_exists($phpmailer, 'setError')) {
                $phpmailer->setError('Email blocked by LGL Email Blocker');
            } else {
                $phpmailer->ErrorInfo = 'Email blocked by LGL Email Blocker';
            }
            
            $this->helper->debug('LGL Email Blocker: PHPMailer email blocked - recipients cleared, SMTP disabled', [
                'to' => $to,
                'subject' => $subject,
                'was_smtp' => $wasSMTP,
                'email_type' => $emailType,
            ]);
        } else {
            // Email allowed - log for debugging
            $this->helper->debug('LGL Email Blocker: Email allowed through PHPMailer (not blocked)', [
                'to' => $to,
                'subject' => $subject,
                'email_type' => $emailType,
            ]);
        }
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
            case self::LEVEL_NONE:
                // Blocking disabled - allow all emails (except cron which is always blocked)
                return false;
                
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
        // Only show notice if blocking is actually active (not disabled)
        $blockingLevel = $this->getBlockingLevel();
        if ($blockingLevel === self::LEVEL_NONE) {
            return; // Blocking disabled, don't show notice
        }
        
        if (!$this->isBlockingEnabled()) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Skip notice on the Email Blocking Settings page (it has its own status section)
        if (isset($_GET['page']) && $_GET['page'] === 'lgl-email-blocking') {
            return;
        }
        
        $blocked_count = $this->operationalData->getBlockedEmailsCount();
        $levelDescriptions = [
            self::LEVEL_NONE => 'Disabled',
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
        $blockingLevel = $this->getBlockingLevel();
        $isEnabled = $this->isBlockingEnabled();
        $isPaused = $this->operationalData->isEmailBlockingPaused();
        
        // Blocking is actively blocking if:
        // 1. Blocking level is NOT 'none'
        // 2. Blocking is enabled (force or env detection)
        // 3. Not temporarily paused
        $isActivelyBlocking = ($blockingLevel !== self::LEVEL_NONE) && $isEnabled && !$isPaused;
        
        return [
            'is_development' => $this->isDevelopmentEnvironment(),
            'is_temporarily_disabled' => $isPaused,
            'is_actively_blocking' => $isActivelyBlocking,
            'is_force_blocking' => $this->isForceBlocking(),
            'blocking_level' => $blockingLevel,
            'environment_info' => $this->getEnvironmentInfo(),
            'env_detection_disabled' => $this->isEnvironmentDetectionDisabled()
        ];
    }
}
