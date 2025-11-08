<?php
/**
 * Admin Helper Functions
 * 
 * Global helper functions for view rendering and admin utilities.
 * Loaded after Composer autoloader in main plugin file.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

use UpstateInternational\LGL\Admin\ViewRenderer;

if (!function_exists('lgl_render_view')) {
    /**
     * Render a view template
     * 
     * Convenience function for ViewRenderer::render().
     * 
     * @param string $view View file path (relative to Views/ directory, without .php extension)
     * @param array $args Variables to extract into view scope
     * @param bool $echo Whether to echo output (true) or return it (false)
     * @return string|void View output if $echo is false
     */
    function lgl_render_view(string $view, array $args = [], bool $echo = true) {
        static $renderer = null;
        
        if ($renderer === null) {
            $renderer = new ViewRenderer();
        }
        
        return $renderer->render($view, $args, $echo);
    }
}

if (!function_exists('lgl_partial')) {
    /**
     * Render a view partial (always returns string)
     * 
     * Convenience function for ViewRenderer::partial().
     * 
     * @param string $view View file path
     * @param array $args Variables to extract into view scope
     * @return string View output
     */
    function lgl_partial(string $view, array $args = []): string {
        static $renderer = null;
        
        if ($renderer === null) {
            $renderer = new ViewRenderer();
        }
        
        return $renderer->partial($view, $args);
    }
}

if (!function_exists('lgl_view_exists')) {
    /**
     * Check if a view exists
     * 
     * @param string $view View file path
     * @return bool True if view exists
     */
    function lgl_view_exists(string $view): bool {
        static $renderer = null;
        
        if ($renderer === null) {
            $renderer = new ViewRenderer();
        }
        
        return $renderer->exists($view);
    }
}

if (!function_exists('lgl_get_container')) {
    /**
     * Get the service container instance
     * 
     * @return \UpstateInternational\LGL\Core\ServiceContainer
     * @throws \RuntimeException If container not available
     */
    function lgl_get_container(): \UpstateInternational\LGL\Core\ServiceContainer {
        $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance();
        $container = $plugin->getContainer();
        
        if ($container === null) {
            throw new \RuntimeException('Service container not available');
        }
        
        return $container;
    }
}

