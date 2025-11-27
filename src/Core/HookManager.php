<?php
/**
 * Hook Manager
 * 
 * Centralized WordPress hook management system.
 * Provides clean, organized registration of WordPress actions and filters
 * with proper dependency injection and service resolution.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Core;

use Psr\Container\ContainerInterface;

/**
 * HookManager Class
 * 
 * Manages WordPress hooks with dependency injection and service resolution
 */
class HookManager {
    
    /**
     * Service container
     * 
     * @var ContainerInterface
     */
    private ContainerInterface $container;
    
    /**
     * Registered hooks
     * 
     * @var array<string, array>
     */
    private array $hooks = [];
    
    /**
     * Hook groups for organized management
     * 
     * @var array<string, array>
     */
    private array $hookGroups = [];
    
    /**
     * Constructor
     * 
     * @param ContainerInterface $container Service container
     */
    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }
    
    /**
     * Register an action hook
     * 
     * @param string $hook Hook name
     * @param callable|string|array $callback Callback function or service method
     * @param int $priority Hook priority
     * @param int $acceptedArgs Number of accepted arguments
     * @param string|null $group Optional hook group
     * @return void
     */
    public function addAction(
        string $hook,
        $callback,
        int $priority = 10,
        int $acceptedArgs = 1,
        ?string $group = null
    ): void {
        $this->registerHook('action', $hook, $callback, $priority, $acceptedArgs, $group);
    }
    
    /**
     * Register a filter hook
     * 
     * @param string $hook Hook name
     * @param callable|string|array $callback Callback function or service method
     * @param int $priority Hook priority
     * @param int $acceptedArgs Number of accepted arguments
     * @param string|null $group Optional hook group
     * @return void
     */
    public function addFilter(
        string $hook,
        $callback,
        int $priority = 10,
        int $acceptedArgs = 1,
        ?string $group = null
    ): void {
        $this->registerHook('filter', $hook, $callback, $priority, $acceptedArgs, $group);
    }
    
    /**
     * Register multiple hooks from configuration
     * 
     * @param array $config Hook configuration array
     * @return void
     */
    public function registerHooks(array $config): void {
        foreach ($config as $type => $hooks) {
            if (!in_array($type, ['actions', 'filters'])) {
                continue;
            }
            
            foreach ($hooks as $hook => $definition) {
                $callback = $definition['callback'] ?? null;
                $priority = $definition['priority'] ?? 10;
                $acceptedArgs = $definition['accepted_args'] ?? 1;
                $group = $definition['group'] ?? null;
                
                if ($callback === null) {
                    continue;
                }
                
                if ($type === 'actions') {
                    $this->addAction($hook, $callback, $priority, $acceptedArgs, $group);
                } else {
                    $this->addFilter($hook, $callback, $priority, $acceptedArgs, $group);
                }
            }
        }
    }
    
    /**
     * Register hooks for a specific group
     * 
     * @param string $group Group name
     * @return void
     */
    public function registerGroup(string $group): void {
        if (!isset($this->hookGroups[$group])) {
            return;
        }
        
        foreach ($this->hookGroups[$group] as $hookData) {
            $this->executeHookRegistration($hookData);
        }
    }
    
    /**
     * Remove an action hook
     * 
     * @param string $hook Hook name
     * @param callable|string|array $callback Callback function or service method
     * @param int $priority Hook priority
     * @return bool
     */
    public function removeAction(string $hook, $callback, int $priority = 10): bool {
        $resolvedCallback = $this->resolveCallback($callback);
        return remove_action($hook, $resolvedCallback, $priority);
    }
    
    /**
     * Remove a filter hook
     * 
     * @param string $hook Hook name
     * @param callable|string|array $callback Callback function or service method
     * @param int $priority Hook priority
     * @return bool
     */
    public function removeFilter(string $hook, $callback, int $priority = 10): bool {
        $resolvedCallback = $this->resolveCallback($callback);
        return remove_filter($hook, $resolvedCallback, $priority);
    }
    
    /**
     * Register a hook
     * 
     * @param string $type Hook type ('action' or 'filter')
     * @param string $hook Hook name
     * @param callable|string|array $callback Callback function or service method
     * @param int $priority Hook priority
     * @param int $acceptedArgs Number of accepted arguments
     * @param string|null $group Optional hook group
     * @return void
     */
    private function registerHook(
        string $type,
        string $hook,
        $callback,
        int $priority,
        int $acceptedArgs,
        ?string $group
    ): void {
        $hookData = [
            'type' => $type,
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs
        ];
        
        // Store hook data
        $this->hooks[$hook][] = $hookData;
        
        // Group hooks if specified
        if ($group !== null) {
            $this->hookGroups[$group][] = $hookData;
            return; // Don't register immediately if grouped
        }
        
        // Register hook immediately
        $this->executeHookRegistration($hookData);
    }
    
    /**
     * Execute hook registration
     * 
     * @param array $hookData Hook data
     * @return void
     */
    private function executeHookRegistration(array $hookData): void {
        $resolvedCallback = $this->resolveCallback($hookData['callback']);
        
        if ($hookData['type'] === 'action') {
            add_action(
                $hookData['hook'],
                $resolvedCallback,
                $hookData['priority'],
                $hookData['accepted_args']
            );
        } else {
            add_filter(
                $hookData['hook'],
                $resolvedCallback,
                $hookData['priority'],
                $hookData['accepted_args']
            );
        }
    }
    
    /**
     * Resolve callback with dependency injection
     * 
     * @param callable|string|array $callback Callback definition
     * @return callable
     * @throws \InvalidArgumentException
     */
    private function resolveCallback($callback): callable {
        // Handle direct callables
        if (is_callable($callback)) {
            return $callback;
        }
        
        // Handle service method calls: 'service_id@method'
        if (is_string($callback) && strpos($callback, '@') !== false) {
            [$serviceId, $method] = explode('@', $callback, 2);
            
            if ($this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                
                if (method_exists($service, $method)) {
                    return [$service, $method];
                }
                
                throw new \InvalidArgumentException(
                    "Method '{$method}' not found on service '{$serviceId}'"
                );
            }
            
            throw new \InvalidArgumentException("Service '{$serviceId}' not found in container");
        }
        
        // Handle array callbacks: ['service_id', 'method']
        if (is_array($callback) && count($callback) === 2) {
            [$serviceId, $method] = $callback;
            
            if ($this->container->has($serviceId)) {
                $service = $this->container->get($serviceId);
                
                if (method_exists($service, $method)) {
                    return [$service, $method];
                }
                
                throw new \InvalidArgumentException(
                    "Method '{$method}' not found on service '{$serviceId}'"
                );
            }
            
            // Handle direct class method calls
            if (is_string($serviceId) && class_exists($serviceId)) {
                return $callback;
            }
            
            throw new \InvalidArgumentException("Service '{$serviceId}' not found in container");
        }
        
        // Handle class static method calls: 'ClassName::method'
        if (is_string($callback) && strpos($callback, '::') !== false) {
            return $callback;
        }
        
        throw new \InvalidArgumentException('Invalid callback format');
    }
    
    /**
     * Get all registered hooks
     * 
     * @return array<string, array>
     */
    public function getHooks(): array {
        return $this->hooks;
    }
    
    /**
     * Get hooks for a specific group
     * 
     * @param string $group Group name
     * @return array
     */
    public function getGroupHooks(string $group): array {
        return $this->hookGroups[$group] ?? [];
    }
    
    /**
     * Check if a hook is registered
     * 
     * @param string $hook Hook name
     * @return bool
     */
    public function hasHook(string $hook): bool {
        return isset($this->hooks[$hook]);
    }
    
    /**
     * Register core LGL hooks
     * 
     * @return void
     */
    public function registerCoreHooks(): void {
        // NOTE: JetFormBuilder custom actions are now handled by ActionRegistry
        // See Plugin.php initializeJetFormBuilderActions() method
        
        // WooCommerce hooks
        // Only process on payment complete to prevent duplicate processing
        // woocommerce_new_order hook removed to prevent duplicate constituent creation
        $this->addAction('woocommerce_payment_complete', 'woocommerce.order_processor@processCompletedOrder', 10, 1);
        $this->addAction('woocommerce_subscription_status_cancelled', 'woocommerce.subscription_handler@handleCancellation', 10, 1);
        $this->addAction('woocommerce_subscription_status_updated', 'woocommerce.subscription_handler@handleStatusUpdate', 10, 3);
        $this->addAction('woocommerce_email_before_order_table', 'email.order_customizer@customizeEmailContent', 10, 4);
        
        // Cache invalidation hooks
        $this->addAction('init', 'cache@initCacheInvalidation', 10, 0);
        
        // Admin hooks
        if (is_admin()) {
            $this->addAction('wp_dashboard_setup', 'dashboard@registerWidgets', 10, 0);
        }
        
        // Email management hooks
        // DISABLED: Email Blocker module - conflicts with WPSMTP Pro email blocking
        // Email blocking is now handled by WPSMTP Pro plugin's email blocking module
        // $this->addAction('plugins_loaded', 'email.blocker@init', 10, 0);
        $this->addAction('wp_loaded', 'email.daily_manager@init', 10, 0);
    }
    
    /**
     * Register hooks from configuration file
     * 
     * @param string $configFile Path to configuration file
     * @return void
     * @throws \InvalidArgumentException
     */
    public function loadHooksFromConfig(string $configFile): void {
        if (!file_exists($configFile)) {
            throw new \InvalidArgumentException("Hook configuration file not found: {$configFile}");
        }
        
        $config = require $configFile;
        
        if (!is_array($config)) {
            throw new \InvalidArgumentException("Hook configuration must return an array");
        }
        
        $this->registerHooks($config);
    }
}
