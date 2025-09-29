<?php
/**
 * JetFormBuilder Action Registry
 * 
 * Centralized registration and management of JetFormBuilder custom actions.
 * Provides clean separation of concerns and dependency injection for actions.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder;

use UpstateInternational\LGL\JetFormBuilder\Actions\JetFormActionInterface;
use UpstateInternational\LGL\Core\ServiceContainer;
use Psr\Container\ContainerInterface;

/**
 * ActionRegistry Class
 * 
 * Manages JetFormBuilder custom actions with dependency injection
 */
class ActionRegistry {
    
    /**
     * Service container
     * 
     * @var ContainerInterface
     */
    private ContainerInterface $container;
    
    /**
     * Registered actions
     * 
     * @var array<string, JetFormActionInterface>
     */
    private array $actions = [];
    
    /**
     * Action instances cache
     * 
     * @var array<string, JetFormActionInterface>
     */
    private array $instances = [];
    
    /**
     * Constructor
     * 
     * @param ContainerInterface $container Service container
     */
    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }
    
    /**
     * Register an action
     * 
     * @param string $actionClass Action class name
     * @return void
     * @throws \InvalidArgumentException
     */
    public function register(string $actionClass): void {
        if (!class_exists($actionClass)) {
            throw new \InvalidArgumentException("Action class '{$actionClass}' does not exist");
        }
        
        if (!is_subclass_of($actionClass, JetFormActionInterface::class)) {
            throw new \InvalidArgumentException(
                "Action class '{$actionClass}' must implement JetFormActionInterface"
            );
        }
        
        // Get action instance to retrieve name
        $action = $this->getActionInstance($actionClass);
        $actionName = $action->getName();
        
        // Store action class for lazy loading
        $this->actions[$actionName] = $actionClass;
        
        // Register WordPress hook
        $this->registerWordPressHook($action);
    }
    
    /**
     * Register multiple actions
     * 
     * @param array<string> $actionClasses Array of action class names
     * @return void
     */
    public function registerMultiple(array $actionClasses): void {
        foreach ($actionClasses as $actionClass) {
            $this->register($actionClass);
        }
    }
    
    /**
     * Get action instance
     * 
     * @param string $actionClass Action class name
     * @return JetFormActionInterface
     */
    private function getActionInstance(string $actionClass): JetFormActionInterface {
        // Return cached instance if available
        if (isset($this->instances[$actionClass])) {
            return $this->instances[$actionClass];
        }
        
        // Try to get from container first
        if ($this->container->has($actionClass)) {
            $instance = $this->container->get($actionClass);
        } else {
            // Fallback to direct instantiation with auto-injection
            $instance = $this->instantiateWithDependencies($actionClass);
        }
        
        if (!$instance instanceof JetFormActionInterface) {
            throw new \InvalidArgumentException(
                "Action '{$actionClass}' must implement JetFormActionInterface"
            );
        }
        
        // Cache instance
        $this->instances[$actionClass] = $instance;
        
        return $instance;
    }
    
    /**
     * Instantiate class with dependency injection
     * 
     * @param string $className Class name
     * @return object
     * @throws \ReflectionException
     */
    private function instantiateWithDependencies(string $className): object {
        $reflection = new \ReflectionClass($className);
        
        // Handle classes without constructor
        if (!$reflection->hasMethod('__construct')) {
            return $reflection->newInstance();
        }
        
        $constructor = $reflection->getMethod('__construct');
        $parameters = $constructor->getParameters();
        
        $dependencies = [];
        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                
                // Try to resolve from container
                if ($this->container->has($typeName)) {
                    $dependencies[] = $this->container->get($typeName);
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
                "Cannot resolve parameter '{$parameter->getName()}' for class '{$className}'"
            );
        }
        
        return $reflection->newInstanceArgs($dependencies);
    }
    
    /**
     * Register WordPress hook for action
     * 
     * @param JetFormActionInterface $action Action instance
     * @return void
     */
    private function registerWordPressHook(JetFormActionInterface $action): void {
        $hookName = 'jet-form-builder/custom-action/' . $action->getName();
        
        add_action(
            $hookName,
            [$action, 'handle'],
            $action->getPriority(),
            $action->getAcceptedArgs()
        );
    }
    
    /**
     * Get registered action by name
     * 
     * @param string $actionName Action name
     * @return JetFormActionInterface|null
     */
    public function getAction(string $actionName): ?JetFormActionInterface {
        if (!isset($this->actions[$actionName])) {
            return null;
        }
        
        return $this->getActionInstance($this->actions[$actionName]);
    }
    
    /**
     * Check if action is registered
     * 
     * @param string $actionName Action name
     * @return bool
     */
    public function hasAction(string $actionName): bool {
        return isset($this->actions[$actionName]);
    }
    
    /**
     * Get all registered action names
     * 
     * @return array<string>
     */
    public function getRegisteredActions(): array {
        return array_keys($this->actions);
    }
    
    /**
     * Unregister an action
     * 
     * @param string $actionName Action name
     * @return bool
     */
    public function unregister(string $actionName): bool {
        if (!$this->hasAction($actionName)) {
            return false;
        }
        
        // Remove WordPress hook
        $hookName = 'jet-form-builder/custom-action/' . $actionName;
        
        if (isset($this->instances[$this->actions[$actionName]])) {
            $action = $this->instances[$this->actions[$actionName]];
            remove_action($hookName, [$action, 'handle'], $action->getPriority());
        }
        
        // Remove from registry
        unset($this->actions[$actionName]);
        
        // Clear cached instance
        if (isset($this->instances[$this->actions[$actionName]])) {
            unset($this->instances[$this->actions[$actionName]]);
        }
        
        return true;
    }
    
    /**
     * Register all core LGL actions
     * 
     * @return void
     */
    public function registerCoreActions(): void {
        $coreActions = [
            \UpstateInternational\LGL\JetFormBuilder\Actions\UserRegistrationAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipUpdateAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipRenewalAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\ClassRegistrationAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\EventRegistrationAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\FamilyMemberAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\UserEditAction::class,
            \UpstateInternational\LGL\JetFormBuilder\Actions\MembershipDeactivationAction::class,
        ];
        
        $this->registerMultiple($coreActions);
    }
    
    /**
     * Get action statistics
     * 
     * @return array
     */
    public function getStats(): array {
        return [
            'total_registered' => count($this->actions),
            'instantiated' => count($this->instances),
            'actions' => $this->getRegisteredActions()
        ];
    }
}
