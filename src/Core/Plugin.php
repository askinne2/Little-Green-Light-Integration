<?php
/**
 * Main Plugin Class
 * 
 * Central entry point for the Little Green Light Integration plugin.
 * Handles plugin initialization, dependency management, and service registration.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

use UpstateInternational\LGL\Admin\DashboardWidgets;
use UpstateInternational\LGL\Email\DailyEmailManager;
// DISABLED: EmailBlocker module - conflicts with WPSMTP Pro email blocking
// use UpstateInternational\LGL\Email\EmailBlocker;
use UpstateInternational\LGL\WooCommerce\SubscriptionRenewalManager;
use UpstateInternational\LGL\Core\ServiceContainer;
use UpstateInternational\LGL\Core\HookManager;
use UpstateInternational\LGL\Core\LegacyCompatibility;
use UpstateInternational\LGL\JetFormBuilder\ActionRegistry;
use Psr\Container\ContainerInterface;

/**
 * Plugin Class
 * 
 * Main plugin orchestrator following modern PHP practices
 */
class Plugin {
    
    /**
     * Plugin version
     */
    const VERSION = '2.0.0';
    
    /**
     * Plugin instance
     * 
     * @var Plugin|null
     */
    private static $instance = null;
    
    /**
     * Plugin file path
     * 
     * @var string
     */
    private $plugin_file;
    
    /**
     * Plugin directory path
     * 
     * @var string
     */
    private $plugin_dir;
    
    /**
     * Plugin URL
     * 
     * @var string
     */
    private $plugin_url;
    
    /**
     * Service container
     * 
     * @var ServiceContainer
     */
    private ServiceContainer $container;
    
    /**
     * Hook manager
     * 
     * @var HookManager
     */
    private HookManager $hookManager;
    
    /**
     * Get plugin instance
     * 
     * @param string|null $plugin_file Main plugin file path
     * @return Plugin
     */
    public static function getInstance($plugin_file = null) {
        if (self::$instance === null) {
            self::$instance = new self($plugin_file);
        }
        
        return self::$instance;
    }
    
    /**
     * Private constructor
     * 
     * @param string|null $plugin_file Main plugin file path
     */
    private function __construct($plugin_file = null) {
        $this->plugin_file = $plugin_file ?: __DIR__ . '/../../lgl-api.php';
        $this->plugin_dir = dirname($this->plugin_file);
        $this->plugin_url = plugin_dir_url($this->plugin_file);
        
        // Initialize service container and hook manager
        $this->container = ServiceContainer::getInstance();
        $this->hookManager = new HookManager($this->container);
        
        $this->initializePlugin();
    }
    
    /**
     * Initialize the plugin
     */
    private function initializePlugin() {
        // Register activation/deactivation hooks
        register_activation_hook($this->plugin_file, [$this, 'onActivation']);
        register_deactivation_hook($this->plugin_file, [$this, 'onDeactivation']);
        
        // Initialize services
        add_action('plugins_loaded', [$this, 'initializeServices'], 10);
        add_action('init', [$this, 'initializeHooks'], 20);
        add_action('admin_init', [$this, 'initializeAdminSettings'], 30);
        
        // Load legacy compatibility layer
        $this->initializeLegacyCompatibility();
    }
    
    /**
     * Initialize services
     */
    public function initializeServices() {
        // Initialize services through container for proper DI
        try {
            // Initialize cache manager
            $this->container->get('cache.manager');
            
            // Initialize utilities
            $this->container->get('utilities');
            
            // Initialize JetEngine CCT API (critical for CCT registrations)
            // Ensure class is loaded (fallback if autoloader not regenerated)
            if (!class_exists('\UpstateInternational\LGL\JetEngine\CctApi')) {
                $cct_api_file = LGL_PLUGIN_DIR . 'src/JetEngine/CctApi.php';
                if (file_exists($cct_api_file)) {
                    require_once $cct_api_file;
                }
            }
            \UpstateInternational\LGL\JetEngine\CctApi::init();
            
            // Initialize SettingsHandler (needed both admin and frontend for ApiSettings injection)
            $settingsHandler = $this->container->get('admin.settings_handler');
            $settingsHandler->initialize();
            
            // Initialize admin-only services
            if (is_admin()) {
                $this->container->get('admin.dashboard_widgets');
                
                // Initialize AssetManager
                $assetManager = $this->container->get('admin.asset_manager');
                $assetManager->initialize();
                
                // Initialize admin menu and testing
                $adminMenuManager = $this->container->get('admin.menu_manager');
                $adminMenuManager->initialize();
                $testingHandler = $this->container->get('admin.testing_handler');
                $testingHandler->initialize();
            }
            
            // Initialize email services
            $this->container->get('email.daily_manager');
            
            // DISABLED: Email Blocker module - conflicts with WPSMTP Pro email blocking
            // Email blocking is now handled by WPSMTP Pro plugin's email blocking module
            // See: WordPress Admin → WP Mail SMTP → Settings → Email Controls
            /*
            // Initialize email blocker with DI
            $emailBlocker = $this->container->get('email.blocker');
            $emailBlocker->init();
            
            // Register email blocker diagnostic tool (accessible via ?lgl_email_diagnostic=1)
            add_action('admin_init', function() use ($emailBlocker) {
                if (!isset($_GET['lgl_email_diagnostic']) || !current_user_can('manage_options')) {
                    return;
                }
                
                // Render diagnostic tool directly (embedded to ensure it's always available)
                $this->renderEmailBlockerDiagnostic($emailBlocker);
                exit;
            }, 5);
            */
            
            // Initialize WooCommerce services
            if (class_exists('WooCommerce')) {
                $this->container->get('woocommerce.subscription_renewal');
                
                // Initialize SubscriptionRenewalManager shortcodes and hooks
                SubscriptionRenewalManager::init();
                
                $this->container->get('woocommerce.order_processor');
                $this->container->get('woocommerce.subscription_handler');
                
                // Initialize Cart Validator (registers validation hooks)
                $this->container->get('woocommerce.cart_validator');
                
                // Initialize Coupon Role Meta (adds role assignment fields to coupons)
                $this->container->get('woocommerce.coupon_role_meta');
                
                // Register custom WooCommerce email classes
                $this->registerWooCommerceEmails();
            }
            
            // Initialize JetFormBuilder actions
            if (class_exists('Jet_Form_Builder\\Plugin')) {
                $this->initializeJetFormBuilderActions();
            }
            
            // Initialize Membership services (only if classes are available)
            if (class_exists('\UpstateInternational\LGL\Memberships\MembershipNotificationMailer')) {
                $this->initializeMembershipServices();
            } else {
                \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Membership services not available - skipping initialization');
            }
            
            // Initialize LGL services through container
            $this->container->get('lgl.connection');
            $this->container->get('lgl.helper');
            $this->container->get('lgl.api_settings');
            $this->container->get('lgl.constituents');
            $this->container->get('lgl.payments');
            $this->container->get('lgl.wp_users');
            $this->container->get('lgl.relations_manager');
            
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: All services initialized successfully via DI container');
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin Service Initialization Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize hooks and filters
     */
    public function initializeHooks() {
        // Flush rewrite rules if activation flag is set (deferred from activation hook)
        // This prevents memory exhaustion during plugin activation on large sites
        if (get_transient('lgl_flush_rewrite_rules')) {
            flush_rewrite_rules();
            delete_transient('lgl_flush_rewrite_rules');
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Rewrite rules flushed (deferred from activation)');
        }
        
        // Register all core hooks through HookManager
        $this->hookManager->registerCoreHooks();
        
        // Legacy shortcode support
        $this->hookManager->addAction('template_redirect', [$this, 'handleLegacyShortcodes'], 10);
        
        // Initialize JetEngine relationship deletion hook for family slot syncing
        $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
        $helper->hookJetEngineRelationshipDeletion();
        
        // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: All hooks initialized successfully via HookManager');
    }
    
    /**
     * Initialize JetFormBuilder actions
     */
    private function initializeJetFormBuilderActions(): void {
        try {
            $actionRegistry = new ActionRegistry($this->container);
            
            // Register all JetFormBuilder actions (WordPress hooks are registered automatically)
            $actions = [
                \UpstateInternational\LGL\JetFormBuilder\Actions\UserRegistrationAction::class, // @deprecated
                \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipUpdateAction::class, // @deprecated - use WooCommerce checkout
                \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipRenewalAction::class,
                \UpstateInternational\LGL\JetFormBuilder\Actions\ClassRegistrationAction::class, // @deprecated - CourseStorm handles new registrations
                \UpstateInternational\LGL\JetFormBuilder\Actions\EventRegistrationAction::class,
                \UpstateInternational\LGL\JetFormBuilder\Actions\FamilyMemberAction::class,
                \UpstateInternational\LGL\JetFormBuilder\Actions\FamilyMemberDeactivationAction::class,
                \UpstateInternational\LGL\JetFormBuilder\Actions\UserEditAction::class,
                \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipDeactivationAction::class,
            ];
            
            foreach ($actions as $actionClass) {
                // Force autoloader to check for class
                if (!class_exists($actionClass, true)) {
                    \UpstateInternational\LGL\LGL\Helper::getInstance()->debug("LGL Plugin: Action class '{$actionClass}' not found, skipping registration");
                    continue;
                }
                
                try {
                    $actionRegistry->register($actionClass);
                } catch (\Exception $e) {
                    \UpstateInternational\LGL\LGL\Helper::getInstance()->debug("LGL Plugin: Error registering action '{$actionClass}': " . $e->getMessage());
                }
            }
            
            // Actions are automatically registered with WordPress when calling register()
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: JetFormBuilder actions initialized successfully - ' . count($actionRegistry->getRegisteredActions()) . ' actions registered');
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin JetFormBuilder Actions Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize Membership services
     */
    private function initializeMembershipServices(): void {
        try {
            // Initialize membership services
            $this->container->get('memberships.renewal_strategy_manager');
            $this->container->get('memberships.notification_mailer');
            $this->container->get('memberships.user_manager');
            $this->container->get('memberships.renewal_manager');
            $this->container->get('memberships.cron_manager');
            
            // Initialize migration utility (registers shortcode)
            $this->container->get('admin.membership_migration_utility');
            
            // Initialize testing utility (registers shortcode)
            $this->container->get('admin.membership_testing_utility');
            
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Membership services initialized successfully');
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin Membership Services Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize admin settings manager
     */
    public function initializeAdminSettings(): void {
        if (!is_admin()) {
            return;
        }
        
        try {
            // Note: admin.settings_manager was removed - SettingsHandler is now used instead
            // The SettingsHandler is initialized in initializeServices() method
            
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Admin settings manager was removed - using SettingsHandler instead');
            
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin Admin Settings Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize LGL services
     */
    private function initializeLGLServices() {
        // Load compatibility shim for legacy code
        if (!class_exists('LGL_API')) {
            require_once $this->plugin_dir . '/includes/lgl-api-compat.php';
        }
        
        // Load remaining legacy files that depend on LGL_API
        require_once $this->plugin_dir . '/includes/lgl-connections.php';
        require_once $this->plugin_dir . '/includes/lgl-helper.php';
        require_once $this->plugin_dir . '/includes/lgl-wp-users.php';
        require_once $this->plugin_dir . '/includes/lgl-constituents.php';
        require_once $this->plugin_dir . '/includes/lgl-payments.php';
        require_once $this->plugin_dir . '/includes/lgl-relations-manager.php';
        require_once $this->plugin_dir . '/includes/lgl-api-settings.php';
    }
    
    /**
     * Handle legacy shortcodes
     */
    public function handleLegacyShortcodes() {
        // Maintain backward compatibility with existing shortcode system
        if (class_exists('LGL_API')) {
            $lgl = \LGL_API::get_instance();
            $lgl->shortcode_init();
        }
    }
    
    /**
     * Initialize legacy compatibility layer
     */
    private function initializeLegacyCompatibility(): void {
        // Check if LegacyCompatibility class is available
        if (!class_exists('\UpstateInternational\LGL\Core\LegacyCompatibility')) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: LegacyCompatibility class not found - loading legacy files directly');
            $this->loadLegacyFilesDirect();
            return;
        }
        
        try {
            // Initialize the legacy compatibility layer
            LegacyCompatibility::initialize();
            LegacyCompatibility::provideLegacyWrappers();
            // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Legacy compatibility layer initialized successfully');
        } catch (\Exception $e) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Legacy compatibility error: ' . $e->getMessage());
            $this->loadLegacyFilesDirect();
        }
    }
    
    /**
     * Load legacy files directly (fallback method)
     */
    private function loadLegacyFilesDirect(): void {
        // Fallback method to load essential legacy files directly
        $legacyIncludes = [
            // CRITICAL: Load the legacy API bridge first (provides LGL_API class)
            'includes/lgl-api-legacy-bridge.php',  // Legacy LGL_API bridge
            
            'includes/decrease_registration_counter_on_trash.php',
            'includes/ui_memberships/ui_memberships.php',
            'includes/lgl-connections.php',
            'includes/lgl-wp-users.php',
            'includes/lgl-constituents.php',
            'includes/lgl-payments.php',
            'includes/lgl-relations-manager.php',
            'includes/lgl-helper.php',
            'includes/lgl-api-settings.php',       // Legacy settings (needed by some legacy classes)
            'includes/test_requests.php',
            'includes/admin/dashboard-widgets.php',
            'includes/email/daily-email.php',
            // 'includes/email/email-blocker.php', // REMOVED: Modern version in src/Email/EmailBlocker.php is now used
            'includes/woocommerce/subscription-renewal.php',
            'includes/lgl-cache-manager.php',
            'includes/lgl-utilities.php',
        ];
        
        foreach ($legacyIncludes as $include) {
            $file = $this->plugin_dir . $include;
            if (file_exists($file)) {
                require_once $file;
            }
        }
        
        // Initialize cache invalidation
        add_action('init', function() {
            if (class_exists('\UpstateInternational\LGL\Core\CacheManager')) {
                \UpstateInternational\LGL\Core\CacheManager::initCacheInvalidation();
            } elseif (class_exists('LGL_Cache_Manager')) {
                LGL_Cache_Manager::init_cache_invalidation();
            }
        });
        
        // \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Legacy files loaded directly (fallback mode)');
    }
    
    /**
     * Load legacy compatibility layer (deprecated method)
     */
    private function loadLegacyCompatibility() {
        // This method is deprecated - functionality moved to LegacyCompatibility class
        $this->initializeLegacyCompatibility();
    }
    
    /**
     * Plugin activation handler
     */
    public function onActivation() {
        // Schedule cache cleanup
        CacheManager::scheduleCleanup();
        
        // Schedule daily email if not already scheduled
        if (!wp_next_scheduled(DailyEmailManager::EMAIL_HOOK)) {
            wp_schedule_event(time(), 'daily', DailyEmailManager::EMAIL_HOOK);
        }
        
        // Defer rewrite rules flush to next page load (memory-efficient)
        // This prevents memory exhaustion during activation on large sites
        set_transient('lgl_flush_rewrite_rules', 1, 60);
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Activation completed successfully (rewrite rules flush deferred)');
    }
    
    /**
     * Plugin deactivation handler
     */
    public function onDeactivation() {
        // Clear scheduled events
        wp_clear_scheduled_hook('lgl_cache_cleanup');
        wp_clear_scheduled_hook(DailyEmailManager::EMAIL_HOOK);
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('LGL Plugin: Deactivation completed successfully');
    }
    
    /**
     * Get plugin version
     * 
     * @return string
     */
    public function getVersion() {
        return self::VERSION;
    }
    
    /**
     * Get plugin directory path
     * 
     * @return string
     */
    public function getPluginDir() {
        return $this->plugin_dir;
    }
    
    /**
     * Get plugin URL
     * 
     * @return string
     */
    public function getPluginUrl() {
        return $this->plugin_url;
    }
    
    /**
     * Get plugin file path
     * 
     * @return string
     */
    public function getPluginFile() {
        return $this->plugin_file;
    }
    
    /**
     * Register a service in the container
     * 
     * @param string $name Service name
     * @param callable $factory Service factory
     */
    public function registerService($name, $factory) {
        $this->services[$name] = $factory;
    }
    
    /**
     * Get a service from the container
     * 
     * @param string $name Service name
     * @return mixed
     */
    public function getService($name) {
        if (!isset($this->services[$name])) {
            throw new \Exception("Service '{$name}' not found");
        }
        
        if (is_callable($this->services[$name])) {
            $this->services[$name] = call_user_func($this->services[$name]);
        }
        
        return $this->services[$name];
    }
    
    /**
     * Check if service exists
     * 
     * @param string $name Service name
     * @return bool
     */
    public function hasService($name) {
        return isset($this->services[$name]);
    }
    
    /**
     * Get service container
     * 
     * @return ServiceContainer
     */
    public function getContainer(): ServiceContainer {
        return $this->container;
    }
    
    /**
     * Get hook manager
     * 
     * @return HookManager
     */
    public function getHookManager(): HookManager {
        return $this->hookManager;
    }
    
    /**
     * Register custom WooCommerce email classes
     * 
     * Registers custom email classes with WooCommerce so they appear in
     * WooCommerce > Settings > Emails and can be customized via Kadence Email Designer.
     * 
     * @return void
     */
    private function registerWooCommerceEmails(): void {
        // Register email classes with WooCommerce
        // Priority 20 ensures WooCommerce is fully loaded
        add_filter('woocommerce_email_classes', function($emails) {
            // Only register if classes exist and WooCommerce is available
            if (!class_exists('WC_Email')) {
                return $emails;
            }
            
            // Register Membership Renewal Email
            if (class_exists('\UpstateInternational\LGL\Email\WC_Membership_Renewal_Email')) {
                $email_instance = new \UpstateInternational\LGL\Email\WC_Membership_Renewal_Email();
                
                // Inject SettingsManager if available (for WooCommerce admin settings)
                try {
                    $settingsManager = $this->container->get('admin.settings_manager');
                    if ($settingsManager) {
                        $email_instance->setSettingsManager($settingsManager);
                    }
                } catch (\Exception $e) {
                    // SettingsManager not available, continue without it
                    \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('SettingsManager not available for WC_Membership_Renewal_Email registration');
                }
                
                $emails['WC_Membership_Renewal_Email'] = $email_instance;
            }
            
            // Register Daily Order Summary Email
            if (class_exists('\UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email')) {
                $emails['WC_Daily_Order_Summary_Email'] = new \UpstateInternational\LGL\Email\WC_Daily_Order_Summary_Email();
            }
            
            // Register Family Member Welcome Email
            if (class_exists('\UpstateInternational\LGL\Email\WC_Family_Member_Welcome_Email')) {
                $emails['WC_Family_Member_Welcome_Email'] = new \UpstateInternational\LGL\Email\WC_Family_Member_Welcome_Email();
            }
            
            return $emails;
        }, 20);
    }
    
    /**
     * Render email blocker diagnostic tool
     * 
     * DISABLED: Email blocking now handled by WPSMTP Pro
     * 
     * @param \UpstateInternational\LGL\Email\EmailBlocker $emailBlocker Email blocker instance
     * @return void
     * @deprecated Disabled - use WPSMTP Pro email blocking instead
     */
    private function renderEmailBlockerDiagnostic(\UpstateInternational\LGL\Email\EmailBlocker $emailBlocker): void {
        // DISABLED: This method is no longer used - email blocking handled by WPSMTP Pro
        // Configure email blocking via: WordPress Admin → WP Mail SMTP → Settings → Email Controls
        wp_die('Email blocking diagnostic tool is disabled. Email blocking is now handled by WPSMTP Pro plugin. Configure via: WordPress Admin → WP Mail SMTP → Settings → Email Controls');
        return;
        // Prevent output buffering issues
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set proper headers
        header('Content-Type: text/html; charset=utf-8');
        
        // Get status information
        try {
            $status = $emailBlocker->getBlockingStatus();
            $level = $emailBlocker->getBlockingLevel();
            $isEnabled = $emailBlocker->isBlockingEnabled();
            $isForce = $emailBlocker->isForceBlocking();
            $isDev = $emailBlocker->isDevelopmentEnvironment();
            
            // Environment info
            $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
            $site_url = get_site_url();
            $server_addr = $_SERVER['SERVER_ADDR'] ?? 'Unknown';
            
            // Check if wp_mail filter is registered
            global $wp_filter;
            $has_filter = false;
            $filter_priority = null;
            if (isset($wp_filter['wp_mail'])) {
                foreach ($wp_filter['wp_mail']->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $callback) {
                        if (is_array($callback['function']) && 
                            is_object($callback['function'][0]) && 
                            get_class($callback['function'][0]) === 'UpstateInternational\LGL\Email\EmailBlocker') {
                            $has_filter = true;
                            $filter_priority = $priority;
                            break 2;
                        }
                    }
                }
            }
            
            // Settings check
            $settings = get_option('lgl_integration_settings', []);
            $force_blocking_setting = $settings['force_email_blocking'] ?? false;
            $blocking_level_setting = $settings['email_blocking_level'] ?? 'not_set';
            
            // Blocked emails count
            $blocked_count = $emailBlocker->getStats()['total_blocked'] ?? 0;
            
        } catch (\Exception $e) {
            wp_die('Error getting email blocker status: ' . esc_html($e->getMessage()));
        }
        
        // Output diagnostic HTML
        ?>
<!DOCTYPE html>
<html>
<head>
    <title>LGL Email Blocker Diagnostic</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 2px solid #0073aa; padding-bottom: 10px; }
        .section { margin: 20px 0; padding: 15px; background: #f9f9f9; border-left: 4px solid #0073aa; }
        .success { border-left-color: #46b450; background: #f0f9f0; }
        .error { border-left-color: #dc3232; background: #fef7f7; }
        .warning { border-left-color: #ffb900; background: #fffbf0; }
        .info { border-left-color: #00a0d2; background: #f0f8ff; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #0073aa; color: white; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        .test-button { display: inline-block; padding: 10px 20px; background: #0073aa; color: white; text-decoration: none; border-radius: 3px; margin: 10px 0; }
        .test-button:hover { background: #005a87; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 LGL Email Blocker Diagnostic Tool</h1>
        <p><strong>Generated:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
        
        <!-- Status Summary -->
        <div class="section <?php echo $isEnabled ? 'success' : 'error'; ?>">
            <h2><?php echo $isEnabled ? '✅' : '❌'; ?> Email Blocker Status</h2>
            <table>
                <tr>
                    <th>Setting</th>
                    <th>Value</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>Blocking Enabled</td>
                    <td><?php echo $isEnabled ? 'YES' : 'NO'; ?></td>
                    <td><?php echo $isEnabled ? '✅ Active' : '❌ Inactive'; ?></td>
                </tr>
                <tr>
                    <td>Force Blocking</td>
                    <td><?php echo $isForce ? 'YES' : 'NO'; ?></td>
                    <td><?php echo $isForce ? '✅ Enabled' : '⚠️ Disabled'; ?></td>
                </tr>
                <tr>
                    <td>Development Environment Detected</td>
                    <td><?php echo $isDev ? 'YES' : 'NO'; ?></td>
                    <td><?php echo $isDev ? '✅ Detected' : '❌ Not Detected'; ?></td>
                </tr>
                <tr>
                    <td>Blocking Level</td>
                    <td><code><?php echo esc_html($level); ?></code></td>
                    <td><?php echo $level !== 'none' ? '✅ Set' : '❌ Not Set'; ?></td>
                </tr>
                <tr>
                    <td>wp_mail Filter Registered</td>
                    <td><?php echo $has_filter ? 'YES (Priority: ' . $filter_priority . ')' : 'NO'; ?></td>
                    <td><?php echo $has_filter ? '✅ Registered' : '❌ NOT REGISTERED'; ?></td>
                </tr>
                <tr>
                    <td>Is Actively Blocking</td>
                    <td><?php echo $status['is_actively_blocking'] ? 'YES' : 'NO'; ?></td>
                    <td><?php echo $status['is_actively_blocking'] ? '✅ Blocking' : '❌ Not Blocking'; ?></td>
                </tr>
                <tr>
                    <td>Temporarily Disabled</td>
                    <td><?php echo $status['is_temporarily_disabled'] ? 'YES' : 'NO'; ?></td>
                    <td><?php echo $status['is_temporarily_disabled'] ? '⚠️ Paused' : '✅ Active'; ?></td>
                </tr>
                <tr>
                    <td>Blocked Emails Count</td>
                    <td><?php echo $blocked_count; ?></td>
                    <td><?php echo $blocked_count > 0 ? '📧 ' . $blocked_count . ' blocked' : '📭 None yet'; ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Environment Information -->
        <div class="section info">
            <h2>🌐 Environment Information</h2>
            <table>
                <tr>
                    <th>Setting</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>HTTP Host</td>
                    <td><code><?php echo esc_html($host); ?></code></td>
                </tr>
                <tr>
                    <td>Site URL</td>
                    <td><code><?php echo esc_html($site_url); ?></code></td>
                </tr>
                <tr>
                    <td>Server Address</td>
                    <td><code><?php echo esc_html($server_addr); ?></code></td>
                </tr>
                <tr>
                    <td>Environment Info</td>
                    <td><code><?php echo esc_html($status['environment_info']); ?></code></td>
                </tr>
            </table>
        </div>
        
        <!-- Settings Check -->
        <div class="section <?php echo $force_blocking_setting ? 'success' : 'warning'; ?>">
            <h2>⚙️ Settings Check</h2>
            <table>
                <tr>
                    <th>Setting</th>
                    <th>Database Value</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>force_email_blocking</td>
                    <td><code><?php echo $force_blocking_setting ? 'true' : 'false'; ?></code></td>
                    <td><?php echo $force_blocking_setting ? '✅ Enabled' : '❌ Disabled'; ?></td>
                </tr>
                <tr>
                    <td>email_blocking_level</td>
                    <td><code><?php echo esc_html($blocking_level_setting); ?></code></td>
                    <td><?php echo $blocking_level_setting !== 'not_set' ? '✅ Set' : '⚠️ Not Set'; ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Recommendations -->
        <div class="section <?php echo $isEnabled && $has_filter ? 'success' : 'error'; ?>">
            <h2>💡 Recommendations</h2>
            <ul>
                <?php if (!$isEnabled): ?>
                    <li><strong>❌ CRITICAL:</strong> Email blocking is not enabled. 
                        <a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Enable it here</a></li>
                <?php endif; ?>
                
                <?php if (!$has_filter): ?>
                    <li><strong>❌ CRITICAL:</strong> The wp_mail filter is NOT registered. 
                        This means emails are NOT being blocked. Try deactivating and reactivating the plugin.</li>
                <?php endif; ?>
                
                <?php if (!$isForce && !$isDev): ?>
                    <li><strong>⚠️ WARNING:</strong> Neither force blocking nor development environment detection is active. 
                        <a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Enable "Force Block All Emails"</a> to activate blocking.</li>
                <?php endif; ?>
                
                <?php if ($isEnabled && $has_filter): ?>
                    <li><strong>✅ GOOD:</strong> Email blocker appears to be working correctly!</li>
                <?php endif; ?>
            </ul>
        </div>
        
        <!-- Test Email -->
        <div class="section info">
            <h2>🧪 Test Email Blocking</h2>
            <p>Click the button below to send a test email. It should be blocked if the email blocker is working.</p>
            <a href="<?php echo admin_url('?lgl_email_diagnostic=1&test_email=1'); ?>" class="test-button">Send Test Email</a>
            
            <?php
            if (isset($_GET['test_email'])) {
                echo '<div style="margin-top: 20px; padding: 15px; background: #f0f8ff; border-left: 4px solid #00a0d2;">';
                echo '<h3>Test Email Result:</h3>';
                
                // Clear any previous debug logs for this test
                $test_start_time = microtime(true);
                
                // Send test email
                $test_result = wp_mail(
                    'test@example.com',
                    'LGL Email Blocker Test - ' . date('Y-m-d H:i:s'),
                    'This is a test email to verify the email blocker is working. If you receive this, blocking is NOT working!'
                );
                
                if ($test_result) {
                    echo '<p style="color: #dc3232;"><strong>❌ EMAIL WAS SENT!</strong> This means blocking is NOT working.</p>';
                    echo '<p><strong>Possible reasons:</strong></p>';
                    echo '<ul>';
                    if (!$isEnabled) {
                        echo '<li>❌ Blocking is not enabled (check settings above)</li>';
                    }
                    if (!$has_filter) {
                        echo '<li>❌ wp_mail filter is NOT registered (try deactivating/reactivating plugin)</li>';
                    }
                    if ($level === 'none') {
                        echo '<li>❌ Blocking level is "none" (blocking disabled)</li>';
                    }
                    if ($status['is_temporarily_disabled']) {
                        echo '<li>⚠️ Email blocking is temporarily paused</li>';
                    }
                    if (!$isForce && !$isDev) {
                        echo '<li>⚠️ Neither force blocking nor dev environment detected</li>';
                    }
                    echo '</ul>';
                    echo '<p><strong>Action Required:</strong> <a href="' . admin_url('admin.php?page=lgl-email-blocking') . '">Go to Email Blocking Settings</a> and enable "Force Block All Emails"</p>';
                } else {
                    echo '<p style="color: #46b450;"><strong>✅ EMAIL WAS BLOCKED!</strong> The email blocker is working correctly.</p>';
                }
                
                echo '<p><strong>Check the blocked emails log:</strong> <a href="' . admin_url('admin.php?page=lgl-email-blocking') . '">View Log</a></p>';
                echo '<p><strong>Check debug logs:</strong> <a href="' . admin_url('admin.php?page=lgl-testing') . '">View Debug Logs</a></p>';
                echo '</div>';
            }
            ?>
        </div>
        
        <!-- Quick Actions -->
        <div class="section info">
            <h2>🔧 Quick Actions</h2>
            <ul>
                <li><a href="<?php echo admin_url('admin.php?page=lgl-email-blocking'); ?>">Go to Email Blocking Settings</a></li>
                <li><a href="<?php echo admin_url('admin.php?page=lgl-testing'); ?>">Go to Testing Suite</a></li>
                <li><a href="<?php echo admin_url('plugins.php'); ?>">Manage Plugins</a></li>
            </ul>
        </div>
    </div>
</body>
</html>
        <?php
    }
    
    /**
     * Get service from modern container
     * 
     * @param string $serviceId Service identifier
     * @return mixed
     */
    public function getServiceFromContainer(string $serviceId) {
        return $this->container->get($serviceId);
    }
}
