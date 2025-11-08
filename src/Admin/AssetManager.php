<?php
/**
 * Asset Manager
 * 
 * Manages admin CSS and JavaScript enqueuing with version control and localization.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

namespace UpstateInternational\LGL\Admin;

/**
 * AssetManager Class
 * 
 * Centralized asset management for admin pages
 */
class AssetManager {
    
    /**
     * Plugin version (for asset cache busting)
     * 
     * @var string
     */
    private string $version;
    
    /**
     * Assets URL base path
     * 
     * @var string
     */
    private string $assetsUrl;
    
    /**
     * Constructor
     * 
     * @param string $pluginVersion Plugin version number
     * @param string $pluginUrl Plugin URL
     */
    public function __construct(string $pluginVersion, string $pluginUrl) {
        $this->version = $pluginVersion;
        $this->assetsUrl = trailingslashit($pluginUrl) . 'assets/admin/';
    }
    
    /**
     * Initialize asset enqueuing
     * 
     * @return void
     */
    public function initialize(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }
    
    /**
     * Enqueue admin assets
     * 
     * @param string $hook Current admin page hook
     * @return void
     */
    public function enqueueAssets(string $hook): void {
        // Only load on LGL admin pages
        if (strpos($hook, 'lgl-') === false && strpos($hook, 'lgl_') === false) {
            return;
        }
        
        $this->enqueueStyles();
        $this->enqueueScripts();
    }
    
    /**
     * Enqueue CSS stylesheets
     * 
     * @return void
     */
    private function enqueueStyles(): void {
        // Core admin styles (consolidated from inline styles)
        wp_enqueue_style(
            'lgl-admin-core',
            $this->assetsUrl . 'css/admin-bundle.css',
            [],
            $this->version
        );
    }
    
    /**
     * Enqueue JavaScript files
     * 
     * @return void
     */
    private function enqueueScripts(): void {
        // Core admin scripts (consolidated from inline scripts)
        wp_enqueue_script(
            'lgl-admin-core',
            $this->assetsUrl . 'js/admin-bundle.js',
            ['jquery'],
            $this->version,
            true
        );
        
        // Localize script with data
        wp_localize_script('lgl-admin-core', 'lglAdmin', $this->getLocalizedData());
    }
    
    /**
     * Get localized data for JavaScript
     * 
     * @return array Localized data
     */
    private function getLocalizedData(): array {
        return [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lgl_admin_nonce'),
            'pluginVersion' => $this->version,
            'strings' => [
                'testing' => __('Testing...', 'lgl-api'),
                'connected' => __('Connected', 'lgl-api'),
                'failed' => __('Failed', 'lgl-api'),
                'saving' => __('Saving...', 'lgl-api'),
                'saved' => __('Saved', 'lgl-api'),
                'error' => __('Error', 'lgl-api'),
                'confirm' => __('Are you sure?', 'lgl-api')
            ],
            'urls' => [
                'dashboard' => admin_url('admin.php?page=lgl-integration'),
                'settings' => admin_url('admin.php?page=lgl-settings'),
                'testing' => admin_url('admin.php?page=lgl-testing')
            ]
        ];
    }
    
    /**
     * Get asset URL
     * 
     * @param string $path Asset path relative to assets/admin/
     * @return string Full asset URL
     */
    public function getAssetUrl(string $path): string {
        return $this->assetsUrl . ltrim($path, '/');
    }
    
    /**
     * Check if assets exist
     * 
     * @return array Status of core asset files
     */
    public function checkAssets(): array {
        $pluginDir = dirname($this->assetsUrl);
        
        return [
            'css_exists' => file_exists($pluginDir . '/assets/admin/css/admin-bundle.css'),
            'js_exists' => file_exists($pluginDir . '/assets/admin/js/admin-bundle.js'),
            'assets_url' => $this->assetsUrl
        ];
    }
}

