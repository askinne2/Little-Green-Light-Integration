<?php
/**
 * Shortcode Registry
 * 
 * Centralized registration and management of WordPress shortcodes.
 * Handles plugin shortcodes with dependency injection and validation.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Shortcodes;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Core\ServiceContainer;

/**
 * ShortcodeRegistry Class
 * 
 * Manages shortcode registration and execution
 */
class ShortcodeRegistry {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Service Container
     * 
     * @var ServiceContainer
     */
    private ServiceContainer $container;
    
    /**
     * Registered shortcodes
     * 
     * @var array<string, array>
     */
    private array $shortcodes = [];
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param ServiceContainer $container Service container
     */
    public function __construct(Helper $helper, ServiceContainer $container) {
        $this->helper = $helper;
        $this->container = $container;
    }
    
    /**
     * Initialize and register all shortcodes
     * 
     * @return void
     */
    public function initialize(): void {
        $this->registerCoreShortcodes();
        $this->registerLegacyShortcodes();
        
        $this->helper->debug('ShortcodeRegistry: Initialized', [
            'registered_count' => count($this->shortcodes)
        ]);
    }
    
    /**
     * Register core plugin shortcodes
     * 
     * @return void
     */
    private function registerCoreShortcodes(): void {
        // Register LGL shortcode
        $this->registerShortcode('lgl', [
            'class' => LglShortcode::class,
            'method' => 'handle',
            'description' => 'Main LGL plugin shortcode for updates and debugging'
        ]);
        
        // Register UI Memberships shortcode
        $this->registerShortcode('ui_memberships', [
            'class' => UiMembershipsShortcode::class,
            'method' => 'handle',
            'description' => 'UI Memberships shortcode for membership management'
        ]);
    }
    
    /**
     * Register legacy compatibility shortcodes
     * 
     * @return void
     */
    private function registerLegacyShortcodes(): void {
        // These provide backward compatibility for existing shortcodes
        // that may still be referenced directly in the legacy system
        
        add_action('template_redirect', [$this, 'initializeLegacyShortcodes'], 10);
    }
    
    /**
     * Initialize legacy shortcodes for backward compatibility
     * 
     * @return void
     */
    public function initializeLegacyShortcodes(): void {
        // LGL shortcode (legacy compatibility)
        if (class_exists('LGL_API')) {
            $lgl_api = \LGL_API::get_instance();
            $lgl_api->shortcode_init();
        }
        
        // UI Memberships shortcode (legacy compatibility)
        if (class_exists('UI_Memberships')) {
            $ui_memberships = \UI_Memberships::get_instance();
            $ui_memberships->shortcode_init();
        }
        
        // LGL WP Users shortcode (legacy compatibility)
        if (class_exists('LGL_WP_Users')) {
            $lgl_users = \LGL_WP_Users::get_instance();
            $lgl_users->shortcode_init();
        }
    }
    
    /**
     * Register a shortcode
     * 
     * @param string $tag Shortcode tag
     * @param array $config Shortcode configuration
     * @return void
     */
    public function registerShortcode(string $tag, array $config): void {
        $config = array_merge([
            'class' => null,
            'method' => 'handle',
            'description' => '',
            'attributes' => [],
            'supports_content' => false
        ], $config);
        
        $this->shortcodes[$tag] = $config;
        
        // Register with WordPress
        add_shortcode($tag, [$this, 'executeShortcode']);
        
        $this->helper->debug('ShortcodeRegistry: Registered shortcode', [
            'tag' => $tag,
            'class' => $config['class']
        ]);
    }
    
    /**
     * Execute shortcode
     * 
     * @param array $atts Shortcode attributes
     * @param string $content Shortcode content
     * @param string $tag Shortcode tag
     * @return string Shortcode output
     */
    public function executeShortcode(array $atts = [], string $content = '', string $tag = ''): string {
        if (!isset($this->shortcodes[$tag])) {
            $this->helper->debug('ShortcodeRegistry: Unknown shortcode', $tag);
            return '';
        }
        
        $config = $this->shortcodes[$tag];
        
        try {
            // Get shortcode handler from container
            if ($config['class'] && $this->container->has($config['class'])) {
                $handler = $this->container->get($config['class']);
                $method = $config['method'];
                
                if (method_exists($handler, $method)) {
                    return $handler->$method($atts, $content, $tag);
                }
            }
            
            $this->helper->debug('ShortcodeRegistry: Handler not found', [
                'tag' => $tag,
                'class' => $config['class']
            ]);
            
            return '';
            
        } catch (\Exception $e) {
            $this->helper->debug('ShortcodeRegistry: Shortcode execution error', [
                'tag' => $tag,
                'error' => $e->getMessage()
            ]);
            
            // Return error message only in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return "<!-- Shortcode Error: {$e->getMessage()} -->";
            }
            
            return '';
        }
    }
    
    /**
     * Unregister a shortcode
     * 
     * @param string $tag Shortcode tag
     * @return void
     */
    public function unregisterShortcode(string $tag): void {
        if (isset($this->shortcodes[$tag])) {
            unset($this->shortcodes[$tag]);
            remove_shortcode($tag);
            
            $this->helper->debug('ShortcodeRegistry: Unregistered shortcode', $tag);
        }
    }
    
    /**
     * Check if shortcode is registered
     * 
     * @param string $tag Shortcode tag
     * @return bool
     */
    public function isRegistered(string $tag): bool {
        return isset($this->shortcodes[$tag]);
    }
    
    /**
     * Get registered shortcodes
     * 
     * @return array<string, array> Registered shortcodes
     */
    public function getRegisteredShortcodes(): array {
        return $this->shortcodes;
    }
    
    /**
     * Get shortcode configuration
     * 
     * @param string $tag Shortcode tag
     * @return array|null Shortcode configuration or null if not found
     */
    public function getShortcodeConfig(string $tag): ?array {
        return $this->shortcodes[$tag] ?? null;
    }
    
    /**
     * Validate shortcode attributes
     * 
     * @param string $tag Shortcode tag
     * @param array $atts Provided attributes
     * @return array Validated attributes with defaults
     */
    public function validateAttributes(string $tag, array $atts): array {
        if (!isset($this->shortcodes[$tag])) {
            return $atts;
        }
        
        $config = $this->shortcodes[$tag];
        $defaults = $config['attributes'] ?? [];
        
        return shortcode_atts($defaults, $atts, $tag);
    }
    
    /**
     * Get shortcode documentation
     * 
     * @return array Shortcode documentation
     */
    public function getDocumentation(): array {
        $docs = [];
        
        foreach ($this->shortcodes as $tag => $config) {
            $docs[$tag] = [
                'tag' => $tag,
                'description' => $config['description'],
                'attributes' => $config['attributes'],
                'supports_content' => $config['supports_content'],
                'example' => $this->generateExample($tag, $config)
            ];
        }
        
        return $docs;
    }
    
    /**
     * Generate usage example for shortcode
     * 
     * @param string $tag Shortcode tag
     * @param array $config Shortcode configuration
     * @return string Usage example
     */
    private function generateExample(string $tag, array $config): string {
        $example = "[{$tag}";
        
        if (!empty($config['attributes'])) {
            $example_atts = [];
            foreach ($config['attributes'] as $attr => $default) {
                $example_atts[] = "{$attr}=\"{$default}\"";
            }
            $example .= ' ' . implode(' ', $example_atts);
        }
        
        if ($config['supports_content']) {
            $example .= "]Content here[/{$tag}]";
        } else {
            $example .= "]";
        }
        
        return $example;
    }
    
    /**
     * Get registry status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'registered_count' => count($this->shortcodes),
            'wordpress_shortcodes' => array_keys(get_shortcode_tags()),
            'plugin_shortcodes' => array_keys($this->shortcodes),
            'container_available' => isset($this->container),
            'legacy_classes_available' => [
                'LGL_API' => class_exists('LGL_API'),
                'UI_Memberships' => class_exists('UI_Memberships'),
                'LGL_WP_Users' => class_exists('LGL_WP_Users')
            ]
        ];
    }
}
