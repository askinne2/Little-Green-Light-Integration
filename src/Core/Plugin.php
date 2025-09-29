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
use UpstateInternational\LGL\Email\EmailBlocker;
use UpstateInternational\LGL\WooCommerce\SubscriptionRenewalManager;
use UpstateInternational\LGL\Core\ServiceContainer;
use UpstateInternational\LGL\Core\HookManager;
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
        
        // Load legacy compatibility
        $this->loadLegacyCompatibility();
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
            
            // Initialize admin services
            if (is_admin()) {
                $this->container->get('admin.dashboard_widgets');
                $this->container->get('admin.settings_manager');
            }
            
            // Initialize email services
            $this->container->get('email.daily_manager');
            $this->container->get('email.blocker');
            
            // Initialize WooCommerce services
            if (class_exists('WooCommerce')) {
                $this->container->get('woocommerce.subscription_renewal');
                $this->container->get('woocommerce.order_processor');
                $this->container->get('woocommerce.subscription_handler');
            }
            
            // Initialize JetFormBuilder actions
            if (class_exists('Jet_Form_Builder\\Plugin')) {
                $this->initializeJetFormBuilderActions();
            }
            
            // Initialize Membership services (only if classes are available)
            if (class_exists('\UpstateInternational\LGL\Memberships\MembershipNotificationMailer')) {
                $this->initializeMembershipServices();
            } else {
                error_log('LGL Plugin: Membership services not available - skipping initialization');
            }
            
            // Initialize LGL services through container
            $this->container->get('lgl.connection');
            $this->container->get('lgl.helper');
            $this->container->get('lgl.api_settings');
            $this->container->get('lgl.constituents');
            $this->container->get('lgl.payments');
            $this->container->get('lgl.wp_users');
            $this->container->get('lgl.relations_manager');
            
            error_log('LGL Plugin: All services initialized successfully via DI container');
            
        } catch (\Exception $e) {
            error_log('LGL Plugin Service Initialization Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize hooks and filters
     */
    public function initializeHooks() {
        // Register all core hooks through HookManager
        $this->hookManager->registerCoreHooks();
        
        // Legacy shortcode support
        $this->hookManager->addAction('template_redirect', [$this, 'handleLegacyShortcodes'], 10);
        
        error_log('LGL Plugin: All hooks initialized successfully via HookManager');
    }
    
    /**
     * Initialize JetFormBuilder actions
     */
    private function initializeJetFormBuilderActions(): void {
        try {
            $actionRegistry = new ActionRegistry($this->container);
            
            // Register all JetFormBuilder actions
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\UserRegistrationAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\MembershipUpdateAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\MembershipRenewalAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\ClassRegistrationAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\EventRegistrationAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\FamilyMemberAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\UserEditAction::class);
            $actionRegistry->register(\UpstateInternational\LGL\JetFormBuilder\Actions\MembershipDeactivationAction::class);
            
            // Initialize the registry (this will register all actions with JetFormBuilder)
            $actionRegistry->initialize();
            
            error_log('LGL Plugin: JetFormBuilder actions initialized successfully');
            
        } catch (\Exception $e) {
            error_log('LGL Plugin JetFormBuilder Actions Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize Membership services
     */
    private function initializeMembershipServices(): void {
        try {
            // Initialize membership services
            $this->container->get('memberships.notification_mailer');
            $this->container->get('memberships.user_manager');
            $this->container->get('memberships.renewal_manager');
            $this->container->get('memberships.cron_manager');
            
            error_log('LGL Plugin: Membership services initialized successfully');
            
        } catch (\Exception $e) {
            error_log('LGL Plugin Membership Services Error: ' . $e->getMessage());
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
            $settingsManager = $this->container->get('admin.settings_manager');
            $settingsManager->initialize();
            
            error_log('LGL Plugin: Admin settings manager initialized successfully');
            
        } catch (\Exception $e) {
            error_log('LGL Plugin Admin Settings Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Initialize LGL services
     */
    private function initializeLGLServices() {
        // These will be converted to namespaced classes in subsequent steps
        // For now, ensure legacy classes are loaded
        if (!class_exists('LGL_API')) {
            require_once $this->plugin_dir . '/includes/lgl-connections.php';
            require_once $this->plugin_dir . '/includes/lgl-helper.php';
            require_once $this->plugin_dir . '/includes/lgl-wp-users.php';
            require_once $this->plugin_dir . '/includes/lgl-constituents.php';
            require_once $this->plugin_dir . '/includes/lgl-payments.php';
            require_once $this->plugin_dir . '/includes/lgl-relations-manager.php';
            require_once $this->plugin_dir . '/includes/lgl-api-settings.php';
        }
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
     * Load legacy compatibility layer
     */
    private function loadLegacyCompatibility() {
        // Load remaining legacy files that haven't been converted yet
        require_once $this->plugin_dir . '/includes/decrease_registration_counter_on_trash.php';
        require_once $this->plugin_dir . '/includes/test_requests.php';
        require_once $this->plugin_dir . '/includes/ui_memberships/ui_memberships.php';
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
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        error_log('LGL Plugin: Activation completed successfully');
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
        
        error_log('LGL Plugin: Deactivation completed successfully');
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
     * Get service from modern container
     * 
     * @param string $serviceId Service identifier
     * @return mixed
     */
    public function getServiceFromContainer(string $serviceId) {
        return $this->container->get($serviceId);
    }
}
