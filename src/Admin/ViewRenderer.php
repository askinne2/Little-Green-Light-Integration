<?php
/**
 * View Renderer
 * 
 * Simple template rendering system for admin pages.
 * Loads view files from the Views/ directory and provides variables via extract().
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

namespace UpstateInternational\LGL\Admin;

/**
 * ViewRenderer Class
 * 
 * Renders view templates with provided data
 */
class ViewRenderer {
    
    /**
     * Views directory path
     * 
     * @var string
     */
    private string $viewsDir;
    
    /**
     * Constructor
     * 
     * @param string|null $viewsDir Optional custom views directory path
     */
    public function __construct(?string $viewsDir = null) {
        if ($viewsDir === null) {
            $this->viewsDir = dirname(__FILE__) . '/Views/';
        } else {
            $this->viewsDir = trailingslashit($viewsDir);
        }
    }
    
    /**
     * Render a view template
     * 
     * @param string $view View file path (relative to views directory, without .php extension)
     * @param array $args Variables to extract into view scope
     * @param bool $echo Whether to echo output (true) or return it (false)
     * @return string|void View output if $echo is false
     */
    public function render(string $view, array $args = [], bool $echo = true) {
        $output = $this->loadView($view, $args);
        
        if ($echo) {
            echo $output;
        } else {
            return $output;
        }
    }
    
    /**
     * Render a view partial (always returns string)
     * 
     * @param string $view View file path
     * @param array $args Variables to extract into view scope
     * @return string View output
     */
    public function partial(string $view, array $args = []): string {
        return $this->loadView($view, $args);
    }
    
    /**
     * Load and render a view file
     * 
     * @param string $view View file path
     * @param array $args Variables to extract
     * @return string Rendered view output
     */
    private function loadView(string $view, array $args = []): string {
        $viewFile = $this->viewsDir . $view . '.php';
        
        if (!file_exists($viewFile)) {
            return sprintf(
                '<!-- LGL View Error: View file "%s" not found at %s -->',
                esc_html($view),
                esc_html($viewFile)
            );
        }
        
        // Extract arguments into local scope
        extract($args, EXTR_SKIP);
        
        // Start output buffering
        ob_start();
        
        // Include the view file
        include $viewFile;
        
        // Return the buffered output
        return ob_get_clean();
    }
    
    /**
     * Check if a view exists
     * 
     * @param string $view View file path
     * @return bool True if view exists
     */
    public function exists(string $view): bool {
        $viewFile = $this->viewsDir . $view . '.php';
        return file_exists($viewFile);
    }
    
    /**
     * Get the views directory path
     * 
     * @return string Views directory path
     */
    public function getViewsDir(): string {
        return $this->viewsDir;
    }
}

