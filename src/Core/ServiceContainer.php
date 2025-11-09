<?php
/**
 * Service Container
 * 
 * Modern dependency injection container following PSR-11 standards.
 * Provides lazy loading, configuration-driven service management,
 * and proper dependency resolution for testable architecture.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

/**
 * ServiceContainer Class
 * 
 * PSR-11 compliant dependency injection container
 */
class ServiceContainer implements ContainerInterface {
    
    /**
     * Service definitions
     * 
     * @var array<string, array>
     */
    private array $services = [];
    
    /**
     * Resolved service instances
     * 
     * @var array<string, object>
     */
    private array $instances = [];
    
    /**
     * Service aliases
     * 
     * @var array<string, string>
     */
    private array $aliases = [];
    
    /**
     * Container instance
     * 
     * @var ServiceContainer|null
     */
    private static ?ServiceContainer $instance = null;
    
    /**
     * Get container instance
     * 
     * @return ServiceContainer
     */
    public static function getInstance(): ServiceContainer {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Private constructor
     */
    private function __construct() {
        $this->registerCoreServices();
    }
    
    /**
     * Register a service definition
     * 
     * @param string $id Service identifier
     * @param callable|string|array $definition Service definition
     * @param array $dependencies Service dependencies
     * @return void
     */
    public function register(string $id, $definition, array $dependencies = []): void {
        $this->services[$id] = [
            'definition' => $definition,
            'dependencies' => $dependencies,
            'singleton' => true // Default to singleton
        ];
    }
    
    /**
     * Register a transient service (new instance each time)
     * 
     * @param string $id Service identifier
     * @param callable|string|array $definition Service definition
     * @param array $dependencies Service dependencies
     * @return void
     */
    public function registerTransient(string $id, $definition, array $dependencies = []): void {
        $this->services[$id] = [
            'definition' => $definition,
            'dependencies' => $dependencies,
            'singleton' => false
        ];
    }
    
    /**
     * Register a service alias
     * 
     * @param string $alias Alias name
     * @param string $serviceId Actual service ID
     * @return void
     */
    public function alias(string $alias, string $serviceId): void {
        $this->aliases[$alias] = $serviceId;
    }
    
    /**
     * Check if service exists
     * 
     * @param string $id Service identifier
     * @return bool
     */
    public function has(string $id): bool {
        // Check direct services
        if (isset($this->services[$id])) {
            return true;
        }
        
        // Check aliases
        if (isset($this->aliases[$id])) {
            return $this->has($this->aliases[$id]);
        }
        
        // Check if it's a class that can be auto-resolved
        if (class_exists($id)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get service instance
     * 
     * @param string $id Service identifier
     * @return mixed
     * @throws ServiceNotFoundException
     * @throws ServiceResolutionException
     */
    public function get(string $id) {
        // Resolve alias
        if (isset($this->aliases[$id])) {
            $id = $this->aliases[$id];
        }
        
        // Return cached singleton instance
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        
        // Check if service is registered
        if (!$this->has($id)) {
            throw new ServiceNotFoundException("Service '{$id}' not found in container");
        }
        
        try {
            $instance = $this->resolve($id);
            
            // Cache singleton instances
            if (isset($this->services[$id]) && $this->services[$id]['singleton']) {
                $this->instances[$id] = $instance;
            }
            
            return $instance;
            
        } catch (\Exception $e) {
            throw new ServiceResolutionException(
                "Failed to resolve service '{$id}': " . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Resolve service instance
     * 
     * @param string $id Service identifier
     * @return mixed
     * @throws \ReflectionException
     */
    private function resolve(string $id) {
        // Use registered service definition
        if (isset($this->services[$id])) {
            $service = $this->services[$id];
            $definition = $service['definition'];
            
            // Handle callable definitions
            if (is_callable($definition)) {
                $dependencies = $this->resolveDependencies($service['dependencies']);
                return $definition($this, ...$dependencies);
            }
            
            // Handle class name definitions
            if (is_string($definition) && class_exists($definition)) {
                return $this->instantiateClass($definition, $service['dependencies']);
            }
            
            // Handle array definitions (factory pattern)
            if (is_array($definition) && isset($definition['class'])) {
                return $this->instantiateClass($definition['class'], $service['dependencies']);
            }
        }
        
        // Auto-resolve class if it exists
        if (class_exists($id)) {
            return $this->instantiateClass($id);
        }
        
        throw new \InvalidArgumentException("Cannot resolve service: {$id}");
    }
    
    /**
     * Instantiate class with dependency injection
     * 
     * @param string $className Class name
     * @param array $explicitDependencies Explicit dependencies
     * @return object
     * @throws \ReflectionException
     */
    private function instantiateClass(string $className, array $explicitDependencies = []): object {
        $reflection = new \ReflectionClass($className);
        
        // Handle classes without constructor
        if (!$reflection->hasMethod('__construct')) {
            return $reflection->newInstance();
        }
        
        $constructor = $reflection->getMethod('__construct');
        $parameters = $constructor->getParameters();
        
        // Resolve constructor dependencies
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $paramName = $parameter->getName();
            
            // Use explicit dependency if provided
            if (isset($explicitDependencies[$paramName])) {
                $dependencies[] = $this->get($explicitDependencies[$paramName]);
                continue;
            }
            
            // Auto-resolve by type hint
            $type = $parameter->getType();
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName)) {
                    $dependencies[] = $this->get($typeName);
                    continue;
                }
            }
            
            // Use default value if available
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }
            
            // Handle optional parameters
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }
            
            throw new \InvalidArgumentException(
                "Cannot resolve parameter '{$paramName}' for class '{$className}'"
            );
        }
        
        return $reflection->newInstanceArgs($dependencies);
    }
    
    /**
     * Resolve explicit dependencies
     * 
     * @param array $dependencies Dependency definitions
     * @return array
     */
    private function resolveDependencies(array $dependencies): array {
        $resolved = [];
        
        foreach ($dependencies as $dependency) {
            if (is_string($dependency) && $this->has($dependency)) {
                $resolved[] = $this->get($dependency);
            } else {
                $resolved[] = $dependency;
            }
        }
        
        return $resolved;
    }
    
    /**
     * Register core services
     * 
     * @return void
     */
    private function registerCoreServices(): void {
        // Register core LGL services (using singleton getInstance() methods)
        // Register both by service name AND by full class name for ActionRegistry compatibility
        $this->register('lgl.connection', function() {
            return \UpstateInternational\LGL\LGL\Connection::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\Connection::class, function() {
            return \UpstateInternational\LGL\LGL\Connection::getInstance();
        });
        
        $this->register('lgl.helper', function() {
            return \UpstateInternational\LGL\LGL\Helper::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\Helper::class, function() {
            return \UpstateInternational\LGL\LGL\Helper::getInstance();
        });
        
        // Register unified SettingsManager service (lazy-loaded to avoid circular dependencies)
        $this->register('admin.settings_manager', function($container) {
            return new \UpstateInternational\LGL\Admin\SettingsManager(
                $container->get('lgl.helper'),
                $container->get('lgl.connection'),
                $container->get('cache.manager')
            );
        });
        
        // Register SettingsHandler WITHOUT SettingsManager dependency (to avoid circular dependency)
        $this->register('admin.settings_handler', function($container) {
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('🔧 ServiceContainer: Creating SettingsHandler (without SettingsManager to avoid circular dependency)...');
            
            // Create handler with basic dependencies only
            // SettingsManager will be lazy-loaded when needed via getSetting()
            $handler = new \UpstateInternational\LGL\Admin\SettingsHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.connection')
            );
            
            // Wire the modern handler to the legacy ApiSettings for delegation
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('🔧 ServiceContainer: Getting ApiSettings instance for injection...');
            $apiSettings = \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('🔧 ServiceContainer: Calling setSettingsHandler...');
            $apiSettings->setSettingsHandler($handler);
            \UpstateInternational\LGL\LGL\Helper::getInstance()->debug('🔧 ServiceContainer: SettingsHandler injection completed');
            
            return $handler;
        });
        
        $this->register('lgl.api_settings', function() {
            return \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\ApiSettings::class, function() {
            return \UpstateInternational\LGL\LGL\ApiSettings::getInstance();
        });
        
        $this->register('lgl.constituents', function() {
            return \UpstateInternational\LGL\LGL\Constituents::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\Constituents::class, function() {
            return \UpstateInternational\LGL\LGL\Constituents::getInstance();
        });
        
        $this->register('lgl.payments', function() {
            return \UpstateInternational\LGL\LGL\Payments::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\Payments::class, function() {
            return \UpstateInternational\LGL\LGL\Payments::getInstance();
        });
        
        $this->register('lgl.wp_users', function() {
            return \UpstateInternational\LGL\LGL\WpUsers::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\WpUsers::class, function() {
            return \UpstateInternational\LGL\LGL\WpUsers::getInstance();
        });
        
        $this->register('lgl.relations_manager', function() {
            return \UpstateInternational\LGL\LGL\RelationsManager::getInstance();
        });
        $this->register(\UpstateInternational\LGL\LGL\RelationsManager::class, function() {
            return \UpstateInternational\LGL\LGL\RelationsManager::getInstance();
        });
        
        // Register core services (non-singletons use class instantiation, singletons use getInstance())
        $this->register('cache.manager', \UpstateInternational\LGL\Core\CacheManager::class);
        $this->register('utilities', \UpstateInternational\LGL\Core\Utilities::class);
        $this->register('test.requests', function() {
            return \UpstateInternational\LGL\Core\TestRequests::getInstance();
        });
        
        // Register admin services
        $this->register('admin.dashboard_widgets', \UpstateInternational\LGL\Admin\DashboardWidgets::class);
        $this->register('admin.asset_manager', function($container) {
            return new \UpstateInternational\LGL\Admin\AssetManager(
                LGL_PLUGIN_VERSION,
                LGL_PLUGIN_URL
            );
        });
        
        // Register operational data manager
        $this->register('admin.operational_data', function($container) {
            return new \UpstateInternational\LGL\Admin\OperationalDataManager(
                $container->get('lgl.helper')
            );
        });
        
        // Register email services
        $this->register('email.blocker', function($container) {
            return new \UpstateInternational\LGL\Email\EmailBlocker(
                $container->get('lgl.helper'),
                $container->get('admin.settings_manager'),
                $container->get('admin.operational_data')
            );
        });
        $this->register('email.daily_manager', \UpstateInternational\LGL\Email\DailyEmailManager::class);
        
        // Register WooCommerce services (with explicit dependency injection)
        $this->register('woocommerce.subscription_renewal', \UpstateInternational\LGL\WooCommerce\SubscriptionRenewalManager::class);
        $this->register('woocommerce.order_processor', function($container) {
            return new \UpstateInternational\LGL\WooCommerce\OrderProcessor(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('woocommerce.membership_handler'),
                $container->get('woocommerce.class_handler'),
                $container->get('woocommerce.event_handler')
            );
        });
        $this->register('woocommerce.subscription_handler', function($container) {
            return new \UpstateInternational\LGL\WooCommerce\SubscriptionHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users')
            );
        });
        
        // Register WooCommerce handler services
        $this->register('memberships.registration_service', function($container) {
            return new \UpstateInternational\LGL\Memberships\MembershipRegistrationService(
                $container->get('lgl.connection'),
                $container->get('lgl.helper'),
                $container->get('lgl.constituents'),
                $container->get('lgl.payments')
            );
        });

        $this->register('woocommerce.membership_handler', function($container) {
            return new \UpstateInternational\LGL\WooCommerce\MembershipOrderHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('memberships.registration_service'),
                $container->get('lgl.api_settings'),
                $container->get('memberships.renewal_strategy_manager')
            );
        });
        $this->register('woocommerce.class_handler', function($container) {
            return new \UpstateInternational\LGL\WooCommerce\ClassOrderHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('jetformbuilder.class_registration_action')
            );
        });
        $this->register('woocommerce.event_handler', function($container) {
            return new \UpstateInternational\LGL\WooCommerce\EventOrderHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('jetformbuilder.event_registration_action')
            );
        });
        
        // Register Email services (with explicit dependency injection)
        $this->register('email.order_customizer', function($container) {
            return new \UpstateInternational\LGL\Email\OrderEmailCustomizer(
                $container->get('lgl.helper')
            );
        });
        
        // Register JetFormBuilder action services
        $this->register('jetformbuilder.user_registration_action', function($container) {
            return new \UpstateInternational\LGL\JetFormBuilder\Actions\UserRegistrationAction(
                $container->get('lgl.connection'),
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('lgl.payments'),
                $container->get('lgl.relations_manager')
            );
        });
        $this->register('jetformbuilder.class_registration_action', function($container) {
            return new \UpstateInternational\LGL\JetFormBuilder\Actions\ClassRegistrationAction(
                $container->get('lgl.connection'),
                $container->get('lgl.helper'),
                $container->get('lgl.payments')
            );
        });
        $this->register('jetformbuilder.event_registration_action', function($container) {
            return new \UpstateInternational\LGL\JetFormBuilder\Actions\EventRegistrationAction(
                $container->get('lgl.connection'),
                $container->get('lgl.helper'),
                $container->get('lgl.payments')
            );
        });
        
        // Register Membership services
        $this->register('memberships.renewal_strategy_manager', function($container) {
            return new \UpstateInternational\LGL\Memberships\RenewalStrategyManager(
                $container->get('lgl.helper')
            );
        });
        $this->register('memberships.notification_mailer', function($container) {
            return new \UpstateInternational\LGL\Memberships\MembershipNotificationMailer(
                $container->get('lgl.helper'),
                '', // template path
                $container->get('admin.settings_manager')
            );
        });
        $this->register('memberships.user_manager', function($container) {
            return new \UpstateInternational\LGL\Memberships\MembershipUserManager(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users')
            );
        });
        $this->register('memberships.renewal_manager', function($container) {
            return new \UpstateInternational\LGL\Memberships\MembershipRenewalManager(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('memberships.notification_mailer'),
                $container->get('memberships.renewal_strategy_manager')
            );
        });
        $this->register('memberships.cron_manager', function($container) {
            return new \UpstateInternational\LGL\Memberships\MembershipCronManager(
                $container->get('lgl.helper'),
                $container->get('memberships.renewal_manager')
            );
        });
        
        // Register Shortcode services
        $this->register('shortcodes.ui_memberships', function($container) {
            return new \UpstateInternational\LGL\Shortcodes\UiMembershipsShortcode(
                $container->get('lgl.helper'),
                $container->get('memberships.renewal_manager'),
                $container->get('memberships.user_manager')
            );
        });
        
        // Register Admin services
        // Removed: admin.settings_manager (over-engineered, replaced by simple SettingsHandler)
        $this->register('admin.sync_log_page', function($container) {
            return new \UpstateInternational\LGL\Admin\SyncLogPage(
                $container->get('lgl.helper')
            );
        });

        $this->register('admin.menu_manager', function($container) {
            return new \UpstateInternational\LGL\Admin\AdminMenuManager(
                $container->get('lgl.helper'),
                $container->get('lgl.api_settings'),
                $container->get('admin.settings_handler'),
                $container->get('admin.sync_log_page'),
                $container->get('admin.renewal_settings_page'),
                $container->get('admin.testing_tools_page'),
                $container->get('admin.email_blocking_page')
            );
        });
        $this->register('admin.testing_handler', function($container) {
            return new \UpstateInternational\LGL\Admin\TestingHandler(
                $container->get('lgl.helper'),
                $container->get('lgl.connection'),
                $container->get('lgl.api_settings'),
                $container->get('lgl.payments'),
                \UpstateInternational\LGL\Core\Plugin::getInstance()
            );
        });
        $this->register('admin.renewal_settings_page', function($container) {
            return new \UpstateInternational\LGL\Admin\RenewalSettingsPage(
                $container->get('admin.settings_manager'),
                $container->get('lgl.helper'),
                $container->get('memberships.renewal_strategy_manager'),
                $container->get('memberships.notification_mailer')
            );
        });
        $this->register('admin.membership_migration_utility', function($container) {
            return new \UpstateInternational\LGL\Admin\MembershipMigrationUtility(
                $container->get('memberships.renewal_strategy_manager'),
                $container->get('lgl.helper')
            );
        });
        $this->register('admin.membership_testing_utility', function($container) {
            return new \UpstateInternational\LGL\Admin\MembershipTestingUtility(
                $container->get('lgl.helper'),
                $container->get('lgl.wp_users'),
                $container->get('memberships.renewal_strategy_manager'),
                $container->get('memberships.renewal_manager'),
                $container->get('memberships.notification_mailer')
            );
        });
        $this->register('admin.testing_tools_page', function($container) {
            return new \UpstateInternational\LGL\Admin\TestingToolsPage();
        });
        $this->register('admin.email_blocking_page', function($container) {
            return new \UpstateInternational\LGL\Admin\EmailBlockingSettingsPage(
                $container->get('admin.settings_manager'),
                $container->get('admin.operational_data'),
                $container->get('lgl.helper'),
                $container->get('email.blocker')
            );
        });
        
        // Register aliases for easier access
        $this->alias('connection', 'lgl.connection');
        $this->alias('helper', 'lgl.helper');
        $this->alias('cache', 'cache.manager');
        $this->alias('dashboard', 'admin.dashboard_widgets');
    }
    
    /**
     * Clear all cached instances (useful for testing)
     * 
     * @return void
     */
    public function clearInstances(): void {
        $this->instances = [];
    }
    
    /**
     * Get all registered service IDs
     * 
     * @return array<string>
     */
    public function getServiceIds(): array {
        return array_keys($this->services);
    }
}

/**
 * Service not found exception
 */
class ServiceNotFoundException extends \Exception implements NotFoundExceptionInterface {
}

/**
 * Service resolution exception
 */
class ServiceResolutionException extends \Exception implements ContainerExceptionInterface {
}

