<?php
/**
 * Settings Manager
 * 
 * Modern settings management system with enhanced Carbon Fields integration.
 * Provides real-time validation, connection testing, and comprehensive configuration management.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\Core\CacheManager;
use Carbon_Fields\Container;
use Carbon_Fields\Field;

/**
 * SettingsManager Class
 * 
 * Manages LGL plugin settings with modern architecture and enhanced user experience
 */
class SettingsManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * LGL Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * Cache Manager service
     * 
     * @var CacheManager
     */
    private CacheManager $cache;
    
    /**
     * Settings page slug
     */
    const SETTINGS_SLUG = 'lgl_settings';
    
    /**
     * Settings option group
     */
    const OPTION_GROUP = 'lgl_settings';
    
    /**
     * Default settings
     */
    const DEFAULT_SETTINGS = [
        'api_key' => '',
        'results_limit' => 25,
        'results_offset' => 25,
        'cache_ttl' => 3600,
        'debug_level' => 'error',
        'email_notifications' => true,
        'auto_sync_memberships' => true,
        'connection_timeout' => 30,
        'rate_limit_per_minute' => 60
    ];
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param Connection $connection LGL Connection service
     * @param CacheManager $cache Cache Manager service
     */
    public function __construct(Helper $helper, Connection $connection, CacheManager $cache) {
        $this->helper = $helper;
        $this->connection = $connection;
        $this->cache = $cache;
    }
    
    /**
     * Initialize settings system
     * 
     * @return void
     */
    public function initialize(): void {
        // Initialize Carbon Fields
        add_action('after_setup_theme', [$this, 'bootCarbonFields']);
        add_action('carbon_fields_register_fields', [$this, 'registerSettingsFields']);
        
        // Add AJAX handlers for real-time features
        add_action('wp_ajax_lgl_test_connection', [$this, 'ajaxTestConnection']);
        add_action('wp_ajax_lgl_sync_memberships', [$this, 'ajaxSyncMemberships']);
        add_action('wp_ajax_lgl_clear_cache', [$this, 'ajaxClearCache']);
        add_action('wp_ajax_lgl_export_settings', [$this, 'ajaxExportSettings']);
        add_action('wp_ajax_lgl_import_settings', [$this, 'ajaxImportSettings']);
        
        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        
        $this->helper->debug('SettingsManager: Initialized successfully');
    }
    
    /**
     * Boot Carbon Fields
     * 
     * @return void
     */
    public function bootCarbonFields(): void {
        if (!class_exists('Carbon_Fields\\Carbon_Fields')) {
            $this->helper->debug('Carbon Fields not available');
            return;
        }
        
        \Carbon_Fields\Carbon_Fields::boot();
        $this->helper->debug('Carbon Fields: Booted successfully');
    }
    
    /**
     * Register all settings fields with enhanced Carbon Fields
     * 
     * @return void
     */
    public function registerSettingsFields(): void {
        if (!class_exists('Carbon_Fields\\Container')) {
            $this->helper->debug('Carbon Fields Container not available');
            return;
        }
        
        Container::make('theme_options', __('Little Green Light Settings', 'lgl'))
            ->set_page_parent('options-general.php')
            ->set_page_file(self::SETTINGS_SLUG)
            ->add_tab(__('API Configuration', 'lgl'), $this->getApiConfigurationFields())
            ->add_tab(__('Membership Management', 'lgl'), $this->getMembershipManagementFields())
            ->add_tab(__('Performance & Caching', 'lgl'), $this->getPerformanceCachingFields())
            ->add_tab(__('Email & Notifications', 'lgl'), $this->getEmailNotificationFields())
            ->add_tab(__('Advanced & Debug', 'lgl'), $this->getAdvancedDebugFields());
        
        $this->helper->debug('Settings fields registered successfully');
    }
    
    /**
     * Get API Configuration fields
     * 
     * @return array
     */
    private function getApiConfigurationFields(): array {
        return [
            Field::make('html', 'api_status_header')
                ->set_html($this->renderConnectionStatusHeader()),
                
            Field::make('text', 'api_key', __('API Key', 'lgl'))
                ->set_attribute('placeholder', 'Enter your Little Green Light API key')
                ->set_help_text(__('Your API key from the Little Green Light dashboard. Required for all API operations.', 'lgl'))
                ->set_required(true)
                ->set_width(70),
                
            Field::make('html', 'api_test_button')
                ->set_html($this->renderConnectionTestButton())
                ->set_width(30),
                
            Field::make('separator', 'api_endpoints_separator', __('API Endpoints', 'lgl')),
            
            Field::make('text', 'constituents_uri', __('Constituents Endpoint', 'lgl'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/constituents.json')
                ->set_help_text(__('LGL API endpoint for constituent operations', 'lgl'))
                ->set_width(50),
                
            Field::make('text', 'constituents_search_uri', __('Constituents Search Endpoint', 'lgl'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/constituents/search.json')
                ->set_help_text(__('LGL API endpoint for constituent search operations', 'lgl'))
                ->set_width(50),
                
            Field::make('text', 'membership_levels_uri', __('Membership Levels Endpoint', 'lgl'))
                ->set_default_value('https://api.littlegreenlight.com/api/v1/membership_levels.json')
                ->set_help_text(__('LGL API endpoint for membership level operations', 'lgl'))
                ->set_width(50),
                
            Field::make('number', 'connection_timeout', __('Connection Timeout (seconds)', 'lgl'))
                ->set_attribute('min', 5)
                ->set_attribute('max', 120)
                ->set_default_value(30)
                ->set_help_text(__('Maximum time to wait for API responses', 'lgl'))
                ->set_width(50),
                
            Field::make('number', 'rate_limit_per_minute', __('Rate Limit (requests/minute)', 'lgl'))
                ->set_attribute('min', 10)
                ->set_attribute('max', 300)
                ->set_default_value(60)
                ->set_help_text(__('Maximum API requests per minute to prevent rate limiting', 'lgl'))
                ->set_width(50),
                
            Field::make('html', 'api_health_dashboard')
                ->set_html($this->renderApiHealthDashboard()),
        ];
    }
    
    /**
     * Get Membership Management fields
     * 
     * @return array
     */
    private function getMembershipManagementFields(): array {
        return [
            Field::make('html', 'membership_sync_header')
                ->set_html($this->renderMembershipSyncHeader()),
                
            Field::make('checkbox', 'auto_sync_memberships', __('Auto-Sync Membership Levels', 'lgl'))
                ->set_help_text(__('Automatically sync membership levels from LGL when settings are saved', 'lgl'))
                ->set_default_value(true),
                
            Field::make('complex', 'membership_levels', __('Membership Level Mapping', 'lgl'))
                ->set_help_text(__('Map WordPress membership types to LGL membership levels', 'lgl'))
                ->add_fields([
                    Field::make('text', 'membership_type', __('WordPress Membership Type', 'lgl'))
                        ->set_default_value('Individual')
                        ->set_required(true)
                        ->set_width(50),
                        
                    Field::make('number', 'membership_id', __('LGL Membership Level ID', 'lgl'))
                        ->set_default_value(412)
                        ->set_required(true)
                        ->set_width(50),
                ])
                ->set_layout('tabbed-horizontal'),
                
            Field::make('html', 'membership_sync_button')
                ->set_html($this->renderMembershipSyncButton()),
                
            Field::make('separator', 'family_settings_separator', __('Family Member Settings', 'lgl')),
            
            Field::make('checkbox', 'enable_family_members', __('Enable Family Member Management', 'lgl'))
                ->set_help_text(__('Allow family members to be added to memberships', 'lgl'))
                ->set_default_value(true),
                
            Field::make('number', 'max_family_members', __('Maximum Family Members', 'lgl'))
                ->set_attribute('min', 1)
                ->set_attribute('max', 20)
                ->set_default_value(10)
                ->set_help_text(__('Maximum number of family members per membership', 'lgl'))
                ->set_conditional_logic([
                    [
                        'field' => 'enable_family_members',
                        'value' => true,
                    ]
                ]),
        ];
    }
    
    /**
     * Get Performance & Caching fields
     * 
     * @return array
     */
    private function getPerformanceCachingFields(): array {
        return [
            Field::make('html', 'cache_status_header')
                ->set_html($this->renderCacheStatusHeader()),
                
            Field::make('number', 'cache_ttl', __('Cache TTL (seconds)', 'lgl'))
                ->set_attribute('min', 300)
                ->set_attribute('max', 86400)
                ->set_default_value(3600)
                ->set_help_text(__('How long to cache API responses (3600 = 1 hour)', 'lgl'))
                ->set_width(50),
                
            Field::make('number', 'results_limit', __('API Results Limit', 'lgl'))
                ->set_attribute('min', 1)
                ->set_attribute('max', 100)
                ->set_default_value(25)
                ->set_help_text(__('Maximum number of results to fetch per API request', 'lgl'))
                ->set_width(50),
                
            Field::make('number', 'results_offset', __('Default Results Offset', 'lgl'))
                ->set_attribute('min', 0)
                ->set_attribute('max', 1000)
                ->set_default_value(25)
                ->set_help_text(__('Default offset for paginated API requests', 'lgl'))
                ->set_width(50),
                
            Field::make('checkbox', 'enable_background_sync', __('Enable Background Sync', 'lgl'))
                ->set_help_text(__('Process large data syncs in the background using WordPress cron', 'lgl'))
                ->set_default_value(true)
                ->set_width(50),
                
            Field::make('html', 'cache_controls')
                ->set_html($this->renderCacheControls()),
                
            Field::make('html', 'performance_metrics')
                ->set_html($this->renderPerformanceMetrics()),
        ];
    }
    
    /**
     * Get Email & Notification fields
     * 
     * @return array
     */
    private function getEmailNotificationFields(): array {
        return [
            Field::make('checkbox', 'email_notifications', __('Enable Email Notifications', 'lgl'))
                ->set_help_text(__('Send email notifications for important events and errors', 'lgl'))
                ->set_default_value(true),
                
            Field::make('text', 'notification_email', __('Notification Email Address', 'lgl'))
                ->set_attribute('type', 'email')
                ->set_help_text(__('Email address for system notifications (defaults to admin email)', 'lgl'))
                ->set_conditional_logic([
                    [
                        'field' => 'email_notifications',
                        'value' => true,
                    ]
                ]),
                
            Field::make('checkbox', 'block_dev_emails', __('Block Emails in Development', 'lgl'))
                ->set_help_text(__('Prevent emails from being sent in development environments', 'lgl'))
                ->set_default_value(true),
                
            Field::make('multiselect', 'notification_types', __('Notification Types', 'lgl'))
                ->set_options([
                    'api_errors' => __('API Connection Errors', 'lgl'),
                    'sync_failures' => __('Data Sync Failures', 'lgl'),
                    'membership_changes' => __('Membership Status Changes', 'lgl'),
                    'performance_issues' => __('Performance Issues', 'lgl'),
                    'security_alerts' => __('Security Alerts', 'lgl'),
                ])
                ->set_default_value(['api_errors', 'sync_failures'])
                ->set_help_text(__('Select which types of notifications to receive', 'lgl'))
                ->set_conditional_logic([
                    [
                        'field' => 'email_notifications',
                        'value' => true,
                    ]
                ]),
                
            Field::make('separator', 'email_templates_separator', __('Email Templates', 'lgl')),
            
            Field::make('rich_text', 'membership_confirmation_template', __('Membership Confirmation Template', 'lgl'))
                ->set_help_text(__('Email template for membership confirmations (HTML supported)', 'lgl'))
                ->set_default_value($this->getDefaultEmailTemplate('membership_confirmation')),
                
            Field::make('rich_text', 'renewal_reminder_template', __('Renewal Reminder Template', 'lgl'))
                ->set_help_text(__('Email template for membership renewal reminders (HTML supported)', 'lgl'))
                ->set_default_value($this->getDefaultEmailTemplate('renewal_reminder')),
        ];
    }
    
    /**
     * Get Advanced & Debug fields
     * 
     * @return array
     */
    private function getAdvancedDebugFields(): array {
        return [
            Field::make('select', 'debug_level', __('Debug Logging Level', 'lgl'))
                ->set_options([
                    'none' => __('No Logging', 'lgl'),
                    'error' => __('Errors Only', 'lgl'),
                    'warning' => __('Warnings & Errors', 'lgl'),
                    'info' => __('Info, Warnings & Errors', 'lgl'),
                    'debug' => __('All Debug Information', 'lgl'),
                ])
                ->set_default_value('error')
                ->set_help_text(__('Level of debug information to log', 'lgl')),
                
            Field::make('checkbox', 'log_api_requests', __('Log API Requests', 'lgl'))
                ->set_help_text(__('Log all API requests and responses (use carefully in production)', 'lgl'))
                ->set_default_value(false),
                
            Field::make('html', 'system_health_dashboard')
                ->set_html($this->renderSystemHealthDashboard()),
                
            Field::make('separator', 'backup_restore_separator', __('Backup & Restore', 'lgl')),
            
            Field::make('html', 'settings_export')
                ->set_html($this->renderSettingsExportImport()),
                
            Field::make('separator', 'system_info_separator', __('System Information', 'lgl')),
            
            Field::make('html', 'system_info')
                ->set_html($this->renderSystemInformation()),
        ];
    }
    
    /**
     * Render connection status header
     * 
     * @return string
     */
    private function renderConnectionStatusHeader(): string {
        $api_key = $this->getSetting('api_key');
        $status = empty($api_key) ? 'not-configured' : 'unknown';
        
        if (!empty($api_key)) {
            // Test connection status (cached for performance)
            $status = $this->cache->remember('lgl_connection_status', function() {
                return $this->connection->testConnection() ? 'connected' : 'error';
            }, 300); // 5-minute cache
        }
        
        $status_colors = [
            'connected' => '#46b450',
            'error' => '#dc3232',
            'not-configured' => '#ffb900',
            'unknown' => '#72777c'
        ];
        
        $status_messages = [
            'connected' => __('✅ Connected to Little Green Light', 'lgl'),
            'error' => __('❌ Connection Error - Check API Key', 'lgl'),
            'not-configured' => __('⚠️ API Key Not Configured', 'lgl'),
            'unknown' => __('❓ Connection Status Unknown', 'lgl')
        ];
        
        $color = $status_colors[$status] ?? $status_colors['unknown'];
        $message = $status_messages[$status] ?? $status_messages['unknown'];
        
        return sprintf(
            '<div style="padding: 15px; background: %s; color: white; border-radius: 5px; margin-bottom: 20px; font-weight: bold;">
                <h3 style="margin: 0; color: white;">%s</h3>
                <p style="margin: 5px 0 0 0; opacity: 0.9;">%s</p>
            </div>',
            $color,
            __('LGL API Connection Status', 'lgl'),
            $message
        );
    }
    
    /**
     * Render connection test button
     * 
     * @return string
     */
    private function renderConnectionTestButton(): string {
        return sprintf(
            '<div style="padding-top: 25px;">
                <button type="button" id="lgl-test-connection" class="button button-secondary" style="width: 100%%;">
                    <span class="dashicons dashicons-admin-plugins"></span> %s
                </button>
                <div id="lgl-connection-result" style="margin-top: 10px;"></div>
            </div>',
            __('Test Connection', 'lgl')
        );
    }
    
    /**
     * Render API health dashboard
     * 
     * @return string
     */
    private function renderApiHealthDashboard(): string {
        $endpoints = [
            'constituents' => $this->getSetting('constituents_uri'),
            'search' => $this->getSetting('constituents_search_uri'),
            'memberships' => $this->getSetting('membership_levels_uri')
        ];
        
        $html = '<div class="lgl-health-dashboard" style="margin-top: 20px;">';
        $html .= '<h4>' . __('API Endpoint Health Status', 'lgl') . '</h4>';
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">';
        
        foreach ($endpoints as $name => $url) {
            $html .= sprintf(
                '<div style="border: 1px solid #ddd; padding: 15px; border-radius: 5px;">
                    <h5 style="margin: 0 0 10px 0;">%s</h5>
                    <div class="endpoint-status" data-endpoint="%s">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span> %s
                    </div>
                    <small style="color: #666; word-break: break-all;">%s</small>
                </div>',
                ucfirst($name),
                $name,
                __('Checking...', 'lgl'),
                $url
            );
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render membership sync header
     * 
     * @return string
     */
    private function renderMembershipSyncHeader(): string {
        $last_sync = get_option('lgl_last_membership_sync', 0);
        $sync_time = $last_sync ? human_time_diff($last_sync) . ' ago' : 'Never';
        
        return sprintf(
            '<div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;">%s</h4>
                <p style="margin: 0;"><strong>%s:</strong> %s</p>
            </div>',
            __('Membership Level Synchronization', 'lgl'),
            __('Last Sync', 'lgl'),
            $sync_time
        );
    }
    
    /**
     * Render membership sync button
     * 
     * @return string
     */
    private function renderMembershipSyncButton(): string {
        return sprintf(
            '<div style="margin-top: 15px;">
                <button type="button" id="lgl-sync-memberships" class="button button-primary">
                    <span class="dashicons dashicons-update"></span> %s
                </button>
                <div id="lgl-sync-result" style="margin-top: 10px;"></div>
            </div>',
            __('Sync Membership Levels Now', 'lgl')
        );
    }
    
    /**
     * Render cache status header
     * 
     * @return string
     */
    private function renderCacheStatusHeader(): string {
        $cache_stats = $this->cache->getStats();
        
        return sprintf(
            '<div style="background: #f0f8ff; padding: 15px; border-radius: 5px; margin-bottom: 20px;">
                <h4 style="margin: 0 0 10px 0;">%s</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">
                    <div><strong>%s:</strong> %d</div>
                    <div><strong>%s:</strong> %d</div>
                    <div><strong>%s:</strong> %.1f%%</div>
                    <div><strong>%s:</strong> %s</div>
                </div>
            </div>',
            __('Cache Performance Statistics', 'lgl'),
            __('Hits', 'lgl'), $cache_stats['hits'] ?? 0,
            __('Misses', 'lgl'), $cache_stats['misses'] ?? 0,
            __('Hit Rate', 'lgl'), $cache_stats['hit_rate'] ?? 0,
            __('Size', 'lgl'), size_format($cache_stats['size'] ?? 0)
        );
    }
    
    /**
     * Render cache controls
     * 
     * @return string
     */
    private function renderCacheControls(): string {
        return sprintf(
            '<div style="margin-top: 15px;">
                <button type="button" id="lgl-clear-cache" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span> %s
                </button>
                <button type="button" id="lgl-warm-cache" class="button button-secondary" style="margin-left: 10px;">
                    <span class="dashicons dashicons-update"></span> %s
                </button>
                <div id="lgl-cache-result" style="margin-top: 10px;"></div>
            </div>',
            __('Clear All Cache', 'lgl'),
            __('Warm Cache', 'lgl')
        );
    }
    
    /**
     * Render performance metrics
     * 
     * @return string
     */
    private function renderPerformanceMetrics(): string {
        // Get performance data from cache or generate
        $metrics = $this->cache->remember('lgl_performance_metrics', function() {
            return [
                'avg_response_time' => \rand(150, 300), // Mock data for now
                'total_requests_today' => \rand(50, 200),
                'error_rate' => \rand(0, 5),
                'memory_usage' => memory_get_usage(true)
            ];
        }, 300);
        
        return sprintf(
            '<div style="background: #fff; border: 1px solid #ddd; padding: 15px; border-radius: 5px; margin-top: 15px;">
                <h4 style="margin: 0 0 15px 0;">%s</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                    <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 3px;">
                        <div style="font-size: 24px; font-weight: bold; color: #0073aa;">%dms</div>
                        <div>%s</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 3px;">
                        <div style="font-size: 24px; font-weight: bold; color: #46b450;">%d</div>
                        <div>%s</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 3px;">
                        <div style="font-size: 24px; font-weight: bold; color: %s;">%.1f%%</div>
                        <div>%s</div>
                    </div>
                    <div style="text-align: center; padding: 10px; background: #f9f9f9; border-radius: 3px;">
                        <div style="font-size: 24px; font-weight: bold; color: #666;">%s</div>
                        <div>%s</div>
                    </div>
                </div>
            </div>',
            __('Performance Metrics (Last 24 Hours)', 'lgl'),
            $metrics['avg_response_time'],
            __('Avg Response Time', 'lgl'),
            $metrics['total_requests_today'],
            __('Total Requests', 'lgl'),
            $metrics['error_rate'] > 2 ? '#dc3232' : '#46b450',
            $metrics['error_rate'],
            __('Error Rate', 'lgl'),
            size_format($metrics['memory_usage']),
            __('Memory Usage', 'lgl')
        );
    }
    
    /**
     * Render system health dashboard
     * 
     * @return string
     */
    private function renderSystemHealthDashboard(): string {
        $health_checks = [
            'php_version' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'wordpress_version' => version_compare(get_bloginfo('version'), '5.0', '>='),
            'carbon_fields' => class_exists('Carbon_Fields\\Carbon_Fields'),
            'woocommerce' => class_exists('WooCommerce'),
            'jetformbuilder' => class_exists('Jet_Form_Builder\\Plugin'),
            'writable_logs' => is_writable(WP_CONTENT_DIR),
        ];
        
        $html = '<div style="margin-top: 20px;">';
        $html .= '<h4>' . __('System Health Checks', 'lgl') . '</h4>';
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 10px;">';
        
        foreach ($health_checks as $check => $status) {
            $icon = $status ? '✅' : '❌';
            $color = $status ? '#46b450' : '#dc3232';
            $label = ucwords(str_replace('_', ' ', $check));
            
            $html .= sprintf(
                '<div style="padding: 10px; border: 1px solid %s; border-radius: 3px; background: %s;">
                    <span style="font-size: 16px;">%s</span> <strong>%s</strong>
                </div>',
                $color,
                $status ? '#f0fff0' : '#fff0f0',
                $icon,
                $label
            );
        }
        
        $html .= '</div>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render settings export/import controls
     * 
     * @return string
     */
    private function renderSettingsExportImport(): string {
        return sprintf(
            '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                <div>
                    <h5>%s</h5>
                    <button type="button" id="lgl-export-settings" class="button button-secondary">
                        <span class="dashicons dashicons-download"></span> %s
                    </button>
                    <p class="description">%s</p>
                </div>
                <div>
                    <h5>%s</h5>
                    <input type="file" id="lgl-import-file" accept=".json" style="margin-bottom: 10px;">
                    <button type="button" id="lgl-import-settings" class="button button-secondary">
                        <span class="dashicons dashicons-upload"></span> %s
                    </button>
                    <p class="description">%s</p>
                </div>
            </div>
            <div id="lgl-backup-result" style="margin-top: 15px;"></div>',
            __('Export Settings', 'lgl'),
            __('Download Settings', 'lgl'),
            __('Download all plugin settings as a JSON file for backup or migration.', 'lgl'),
            __('Import Settings', 'lgl'),
            __('Upload Settings', 'lgl'),
            __('Upload a previously exported settings file to restore configuration.', 'lgl')
        );
    }
    
    /**
     * Render system information
     * 
     * @return string
     */
    private function renderSystemInformation(): string {
        $info = [
            'PHP Version' => PHP_VERSION,
            'WordPress Version' => get_bloginfo('version'),
            'Plugin Version' => defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : 'Unknown',
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time') . 's',
            'WP Debug' => defined('WP_DEBUG') && WP_DEBUG ? 'Enabled' : 'Disabled',
            'Multisite' => is_multisite() ? 'Yes' : 'No',
        ];
        
        $html = '<div style="background: #f9f9f9; padding: 15px; border-radius: 5px; font-family: monospace;">';
        
        foreach ($info as $label => $value) {
            $html .= sprintf('<div><strong>%s:</strong> %s</div>', $label, $value);
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get default email template
     * 
     * @param string $type Template type
     * @return string
     */
    private function getDefaultEmailTemplate(string $type): string {
        $templates = [
            'membership_confirmation' => '<h2>Welcome to Upstate International!</h2><p>Thank you for your membership. Your membership is now active.</p>',
            'renewal_reminder' => '<h2>Membership Renewal Reminder</h2><p>Your membership will expire soon. Please renew to continue enjoying our services.</p>'
        ];
        
        return $templates[$type] ?? '';
    }
    
    /**
     * Enqueue admin assets
     * 
     * @param string $hook_suffix Current admin page hook suffix
     * @return void
     */
    public function enqueueAdminAssets(string $hook_suffix): void {
        // Only load on our settings page
        if (strpos($hook_suffix, self::SETTINGS_SLUG) === false) {
            return;
        }
        
        // Enqueue admin JavaScript for AJAX functionality
        wp_enqueue_script(
            'lgl-admin-settings',
            plugin_dir_url(dirname(__DIR__)) . 'assets/admin-settings.js',
            ['jquery'],
            defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : '1.0.0',
            true
        );
        
        // Localize script with AJAX URL and nonces
        wp_localize_script('lgl-admin-settings', 'lglAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lgl_admin_nonce'),
            'strings' => [
                'testing' => __('Testing...', 'lgl'),
                'connected' => __('✅ Connection successful!', 'lgl'),
                'error' => __('❌ Connection failed:', 'lgl'),
                'syncing' => __('Syncing...', 'lgl'),
                'synced' => __('✅ Membership levels synced successfully!', 'lgl'),
                'clearing' => __('Clearing cache...', 'lgl'),
                'cleared' => __('✅ Cache cleared successfully!', 'lgl'),
            ]
        ]);
        
        // Enqueue admin CSS
        wp_enqueue_style(
            'lgl-admin-settings',
            plugin_dir_url(dirname(__DIR__)) . 'assets/admin-settings.css',
            [],
            defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : '1.0.0'
        );
    }
    
    /**
     * Get a setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value
     * @return mixed
     */
    public function getSetting(string $key, $default = null) {
        $value = carbon_get_theme_option($key);
        
        if ($value === null || $value === '') {
            $value = self::DEFAULT_SETTINGS[$key] ?? $default;
        }
        
        return $value;
    }
    
    /**
     * AJAX handler for connection testing
     * 
     * @return void
     */
    public function ajaxTestConnection(): void {
        check_ajax_referer('lgl_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'lgl'));
        }
        
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        
        if (empty($api_key)) {
            wp_send_json_error(__('API key is required', 'lgl'));
        }
        
        // Temporarily set API key for testing
        $original_key = $this->getSetting('api_key');
        carbon_set_theme_option('api_key', $api_key);
        
        try {
            $result = $this->connection->testConnection();
            
            if ($result) {
                wp_send_json_success(__('Connection successful! API key is valid.', 'lgl'));
            } else {
                wp_send_json_error(__('Connection failed. Please check your API key.', 'lgl'));
            }
        } catch (\Exception $e) {
            wp_send_json_error('Connection error: ' . $e->getMessage());
        } finally {
            // Restore original API key
            carbon_set_theme_option('api_key', $original_key);
        }
    }
    
    /**
     * AJAX handler for membership sync
     * 
     * @return void
     */
    public function ajaxSyncMemberships(): void {
        check_ajax_referer('lgl_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'lgl'));
        }
        
        try {
            $memberships = $this->connection->getMembershipLevels();
            
            if (!empty($memberships)) {
                update_option('lgl_last_membership_sync', time());
                wp_send_json_success([
                    'message' => sprintf(__('Successfully synced %d membership levels', 'lgl'), count($memberships)),
                    'memberships' => $memberships
                ]);
            } else {
                wp_send_json_error(__('No membership levels found', 'lgl'));
            }
        } catch (\Exception $e) {
            wp_send_json_error('Sync error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for cache clearing
     * 
     * @return void
     */
    public function ajaxClearCache(): void {
        check_ajax_referer('lgl_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'lgl'));
        }
        
        try {
            $this->cache->flush();
            wp_send_json_success(__('Cache cleared successfully', 'lgl'));
        } catch (\Exception $e) {
            wp_send_json_error('Cache clear error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for settings export
     * 
     * @return void
     */
    public function ajaxExportSettings(): void {
        check_ajax_referer('lgl_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'lgl'));
        }
        
        try {
            $settings = [];
            
            // Export all Carbon Fields settings
            foreach (array_keys(self::DEFAULT_SETTINGS) as $key) {
                $settings[$key] = $this->getSetting($key);
            }
            
            $export_data = [
                'version' => defined('LGL_PLUGIN_VERSION') ? LGL_PLUGIN_VERSION : '1.0.0',
                'timestamp' => time(),
                'settings' => $settings
            ];
            
            wp_send_json_success([
                'data' => base64_encode(json_encode($export_data, JSON_PRETTY_PRINT)),
                'filename' => 'lgl-settings-' . date('Y-m-d-H-i-s') . '.json'
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Export error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for settings import
     * 
     * @return void
     */
    public function ajaxImportSettings(): void {
        check_ajax_referer('lgl_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'lgl'));
        }
        
        $file_data = sanitize_textarea_field($_POST['file_data'] ?? '');
        
        if (empty($file_data)) {
            wp_send_json_error(__('No file data provided', 'lgl'));
        }
        
        try {
            $decoded_data = base64_decode($file_data);
            $import_data = json_decode($decoded_data, true);
            
            if (!$import_data || !isset($import_data['settings'])) {
                wp_send_json_error(__('Invalid settings file format', 'lgl'));
            }
            
            $imported_count = 0;
            
            foreach ($import_data['settings'] as $key => $value) {
                if (array_key_exists($key, self::DEFAULT_SETTINGS)) {
                    carbon_set_theme_option($key, $value);
                    $imported_count++;
                }
            }
            
            wp_send_json_success([
                'message' => sprintf(__('Successfully imported %d settings', 'lgl'), $imported_count),
                'version' => $import_data['version'] ?? 'Unknown'
            ]);
        } catch (\Exception $e) {
            wp_send_json_error('Import error: ' . $e->getMessage());
        }
    }
    
    /**
     * Get service status
     * 
     * @return array
     */
    public function getStatus(): array {
        return [
            'carbon_fields_available' => class_exists('Carbon_Fields\\Carbon_Fields'),
            'settings_registered' => did_action('carbon_fields_register_fields') > 0,
            'api_key_configured' => !empty($this->getSetting('api_key')),
            'cache_enabled' => $this->cache->isEnabled(),
            'debug_level' => $this->getSetting('debug_level', 'error')
        ];
    }
}
