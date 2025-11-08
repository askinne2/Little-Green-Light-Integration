<?php
/**
 * Admin Menu Manager
 * 
 * MODERNIZED: Uses component system, no inline HTML/CSS/JS
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\ApiSettings;

/**
 * AdminMenuManager Class
 * 
 * Manages the unified LGL admin interface with modern component architecture
 */
class AdminMenuManager {
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * API Settings service
     * 
     * @var ApiSettings
     */
    private ApiSettings $apiSettings;
    
    /**
     * Menu slug
     */
    const MAIN_MENU_SLUG = 'lgl-integration';
    
    /**
     * Settings Handler service
     * 
     * @var SettingsHandler
     */
    private SettingsHandler $settingsHandler;

    /**
     * Sync log page renderer
     *
     * @var SyncLogPage
     */
    private SyncLogPage $syncLogPage;
    
    /**
     * Settings Manager service (lazy-loaded to avoid circular dependency)
     * 
     * @var SettingsManager|null
     */
    private ?SettingsManager $settingsManager = null;
    
    /**
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param ApiSettings $apiSettings API settings service
     * @param SettingsHandler $settingsHandler Settings handler service
     * @param SyncLogPage $syncLogPage Sync log page renderer
     */
    public function __construct(
        Helper $helper,
        ApiSettings $apiSettings,
        SettingsHandler $settingsHandler,
        SyncLogPage $syncLogPage
    ) {
        $this->helper = $helper;
        $this->apiSettings = $apiSettings;
        $this->settingsHandler = $settingsHandler;
        $this->syncLogPage = $syncLogPage;
    }
    
    /**
     * Initialize admin menus
     */
    public function initialize(): void {
        add_action('admin_menu', [$this, 'registerMenus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }
    
    /**
     * Register admin menus
     */
    public function registerMenus(): void {
        // Main LGL Integration menu
        add_menu_page(
            'LGL Integration',
            'LGL Integration',
            'manage_options',
            self::MAIN_MENU_SLUG,
            [$this, 'renderDashboard'],
            'dashicons-networking',
            30
        );
        
        // Dashboard (same as main menu)
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Dashboard',
            'Dashboard',
            'manage_options',
            self::MAIN_MENU_SLUG,
            [$this, 'renderDashboard']
        );
        
        // Settings
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Settings',
            'Settings',
            'manage_options',
            'lgl-settings',
            [$this, 'renderSettings']
        );
        
        // Testing Suite
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Testing Suite',
            'Testing Suite',
            'manage_options',
            'lgl-testing',
            [$this, 'renderTesting']
        );

        // Sync Log
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Sync Log',
            'Sync Log',
            'manage_options',
            'lgl-sync-log',
            [$this, 'renderSyncLog']
        );
    }
    
    /**
     * Enqueue admin assets (delegated to AssetManager)
     */
    public function enqueueAssets($hook): void {
        // AssetManager handles all asset loading automatically
        // This method kept for backward compatibility
    }
    
    /**
     * Render dashboard page - MODERNIZED
     */
    public function renderDashboard(): void {
        $settingsManager = $this->getSettingsManager();
        
        // Build dashboard content with components
        $content = '<div class="lgl-dashboard-grid">';
        
        // System Status Card
        $content .= lgl_partial('components/card', [
            'title' => 'System Status',
            'icon' => '📊',
            'content' => lgl_partial('partials/system-status', [
                'settingsManager' => $settingsManager
            ])
        ]);
        
        // Quick Actions Card
        $quickActions = '<div class="lgl-quick-actions">';
        $quickActions .= lgl_partial('components/button', [
            'text' => '⚙️ Configure Settings',
            'type' => 'primary',
            'href' => admin_url('admin.php?page=lgl-settings')
        ]);
        $quickActions .= lgl_partial('components/button', [
            'text' => '🧪 Run Tests',
            'type' => 'secondary',
            'href' => admin_url('admin.php?page=lgl-testing')
        ]);
        $quickActions .= lgl_partial('components/button', [
            'text' => '🔌 Test Connection',
            'type' => 'secondary',
            'attrs' => ['id' => 'lgl-test-connection-btn', 'data-nonce' => wp_create_nonce('lgl_admin_nonce')]
        ]);
        $quickActions .= '<div id="lgl-connection-result" class="lgl-connection-result" style="display:none; margin-top:15px;"></div>';
        $quickActions .= '</div>';
        
        $content .= lgl_partial('components/card', [
            'title' => 'Quick Actions',
            'icon' => '🚀',
            'content' => $quickActions
        ]);
        
        // Statistics Card
        $content .= lgl_partial('components/card', [
            'title' => 'Statistics',
            'icon' => '📊',
            'content' => lgl_partial('partials/statistics', ['helper' => $this->helper])
        ]);
        
        // Recent Activity Card
        $recentActivity = $this->getRecentActivity();
        $content .= lgl_partial('components/card', [
            'title' => 'Recent Activity',
            'icon' => '📈',
            'content' => $recentActivity
        ]);
        
        $content .= '</div>';
        
        // Architecture Status (full width)
        $content .= lgl_partial('components/card', [
            'title' => 'Architecture Status',
            'icon' => '🏗️',
            'content' => $this->getArchitectureStatus(),
            'class' => 'lgl-full-width'
        ]);
        
        // Render complete page
        lgl_render_view('layouts/admin-page', [
            'title' => 'LGL Integration Dashboard',
            'description' => 'Monitor your system status and access all LGL functionality.',
            'content' => $content
        ]);
    }
    
    /**
     * Render settings page - MODERNIZED
     */
    public function renderSettings(): void {
        $settings = $this->settingsHandler->getSettings();
        $nonce = wp_create_nonce('lgl_admin_nonce');
        
        // Build settings tabs
        $tabs = [
            'api' => 'API Configuration',
            'memberships' => 'Membership Levels',
            'advanced' => 'Advanced Settings'
        ];
        
        $content = '<div class="lgl-settings-page">';
        
        // Tab Navigation
        $content .= '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $active = ($key === 'api') ? 'nav-tab-active' : '';
            $content .= sprintf(
                '<a href="#" class="nav-tab %s" data-tab="lgl-tab-%s">%s</a>',
                $active,
                esc_attr($key),
                esc_html($label)
            );
        }
        $content .= '</h2>';
        
        // API Configuration Tab
        $content .= '<div id="lgl-tab-api" class="lgl-tab-content">';
        $content .= $this->renderApiSettingsForm($settings, $nonce);
        $content .= '</div>';
        
        // Membership Levels Tab
        $content .= '<div id="lgl-tab-memberships" class="lgl-tab-content" style="display:none;">';
        $content .= $this->renderMembershipSettingsForm($settings, $nonce);
        $content .= '</div>';
        
        // Advanced Settings Tab
        $content .= '<div id="lgl-tab-advanced" class="lgl-tab-content" style="display:none;">';
        $content .= $this->renderAdvancedSettingsForm($settings, $nonce);
        $content .= '</div>';
        
        $content .= '</div>';
        
        lgl_render_view('layouts/admin-page', [
            'title' => 'LGL Settings',
            'description' => 'Configure your Little Green Light API integration.',
            'content' => $content
        ]);
    }
    
    /**
     * Render testing page - COMPREHENSIVE SUITE
     */
    public function renderTesting(): void {
        $nonce = wp_create_nonce('lgl_admin_nonce');
        $testUserId = 1214; // Default test user
        
        $content = '<div class="lgl-testing-grid">';
        
        // Connection Test Card
        $content .= $this->renderTestCard(
            'connection',
            '🔌 Connection Test',
            'Test API connectivity and authentication',
            $nonce
        );
        
        // Add Constituent Test Card
        $content .= $this->renderTestCard(
            'add_constituent',
            '➕ Add Constituent',
            'Create new constituent from user ' . $testUserId,
            $nonce,
            ['wordpress_user_id' => $testUserId]
        );
        
        // Update Constituent Test Card
        $content .= $this->renderTestCard(
            'update_constituent',
            '✏️ Update Constituent',
            'Update existing constituent data for user ' . $testUserId,
            $nonce,
            ['wordpress_user_id' => $testUserId]
        );
        
        // Add Membership Test Card
        $content .= $this->renderTestCard(
            'add_membership',
            '🎫 Add Membership',
            'Add membership to constituent (variation 68386)',
            $nonce,
            ['wordpress_user_id' => $testUserId, 'variation_product_id' => 68386]
        );
        
        // Update Membership Test Card
        $content .= $this->renderTestCard(
            'update_membership',
            '🔄 Update Membership',
            'Update membership details for user ' . $testUserId,
            $nonce,
            ['wordpress_user_id' => $testUserId]
        );
        
        // Event Registration Test Card
        $content .= $this->renderTestCard(
            'event_registration',
            '📅 Event Registration',
            'Test event registration flow (variation 83556)',
            $nonce,
            ['wordpress_user_id' => $testUserId, 'variation_product_id' => 83556]
        );
        
        // Class Registration Test Card
        $content .= $this->renderTestCard(
            'class_registration',
            '📚 Class Registration',
            'Test class registration (product 86825)',
            $nonce,
            ['wordpress_user_id' => $testUserId, 'class_product_id' => 86825]
        );
        
        // Full Suite Test Card
        $content .= $this->renderTestCard(
            'full_suite',
            '🚀 Full Test Suite',
            'Run all tests sequentially',
            $nonce,
            ['wordpress_user_id' => $testUserId]
        );
        
        $content .= '</div>';
        
        // Add note about test user
        $content .= '<div class="lgl-testing-note">';
        $content .= '<p><strong>ℹ️ Test Configuration:</strong></p>';
        $content .= '<ul>';
        $content .= '<li>Default Test User: <code>' . $testUserId . '</code> (Andrew Skinner)</li>';
        $content .= '<li>Membership Variation: <code>68386</code> (Individual - $75)</li>';
        $content .= '<li>Event Variation: <code>83556</code></li>';
        $content .= '<li>Class Product: <code>86825</code></li>';
        $content .= '<li>⚠️ Tests run against <strong>LIVE API</strong> - real data will be created/updated in LGL</li>';
        $content .= '</ul>';
        $content .= '</div>';
        
        lgl_render_view('layouts/admin-page', [
            'title' => 'LGL Testing Suite',
            'description' => 'Comprehensive testing for all LGL API operations.',
            'content' => $content
        ]);
    }
    
    /**
     * Render individual test card
     */
    private function renderTestCard(string $testType, string $title, string $description, string $nonce, array $data = []): string {
        $dataAttrs = 'data-test-type="' . esc_attr($testType) . '" ';
        $dataAttrs .= 'data-nonce="' . esc_attr($nonce) . '" ';
        
        foreach ($data as $key => $value) {
            $dataAttrs .= 'data-' . esc_attr($key) . '="' . esc_attr($value) . '" ';
        }
        
        $buttonId = 'lgl-test-' . $testType;
        $resultId = 'lgl-result-' . $testType;
        
        $cardContent = '<div class="lgl-test-card-content">';
        $cardContent .= '<p class="lgl-test-description">' . esc_html($description) . '</p>';
        $cardContent .= '<button type="button" class="button button-primary lgl-test-button" ';
        $cardContent .= 'id="' . esc_attr($buttonId) . '" ' . $dataAttrs . '>';
        $cardContent .= 'Run Test';
        $cardContent .= '</button>';
        $cardContent .= '<div class="lgl-test-result" id="' . esc_attr($resultId) . '" style="margin-top: 15px;"></div>';
        $cardContent .= '</div>';
        
        return lgl_partial('components/card', [
            'title' => $title,
            'content' => $cardContent,
            'class' => 'lgl-test-card'
        ]);
    }
    
    /**
     * Render sync log page
     */
    public function renderSyncLog(): void {
        $this->syncLogPage->render();
    }
    
    // ========================================
    // PRIVATE HELPER METHODS
    // ========================================
    
    /**
     * Lazy-load SettingsManager
     */
    private function getSettingsManager(): ?\UpstateInternational\LGL\Admin\SettingsManager {
        if ($this->settingsManager === null && function_exists('lgl_get_container')) {
            try {
                $container = lgl_get_container();
                if ($container->has('admin.settings_manager')) {
                    $this->settingsManager = $container->get('admin.settings_manager');
                }
            } catch (\Exception $e) {
                // Settings Manager not available
            }
        }
        return $this->settingsManager;
    }
    
    /**
     * Get recent activity data
     */
    private function getRecentActivity(): string {
        $activity = '<div class="lgl-activity-list">';
        $activity .= '<p><em>Recent activity tracking coming soon...</em></p>';
        $activity .= '</div>';
        return $activity;
    }
    
    /**
     * Get architecture status
     */
    private function getArchitectureStatus(): string {
        $status = '<div class="lgl-architecture-status">';
        
        // Check modern architecture components
        $checks = [
            'ServiceContainer' => class_exists('\UpstateInternational\LGL\Core\ServiceContainer'),
            'SettingsManager' => class_exists('\UpstateInternational\LGL\Admin\SettingsManager'),
            'ViewRenderer' => class_exists('\UpstateInternational\LGL\Admin\ViewRenderer'),
            'AssetManager' => class_exists('\UpstateInternational\LGL\Admin\AssetManager'),
            'Components' => function_exists('lgl_render_view'),
        ];
        
        foreach ($checks as $component => $exists) {
            $status .= lgl_partial('components/status-item', [
                'label' => $component,
                'value' => $exists ? 'Active' : 'Missing',
                'status' => $exists ? 'success' : 'error'
            ]);
        }
        
        $status .= '</div>';
        return $status;
    }
    
    /**
     * Render API settings form
     */
    private function renderApiSettingsForm(array $settings, string $nonce): string {
        ob_start();
        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('lgl_api_settings', '_wpnonce'); ?>
            <input type="hidden" name="action" value="lgl_save_api_settings" />
            
            <table class="form-table">
                <tr>
                    <th><label for="lgl_api_url">API URL</label></th>
                    <td>
                        <input type="url" name="lgl_api_url" id="lgl_api_url" 
                               value="<?php echo esc_attr($settings['api_url'] ?? ''); ?>" 
                               class="regular-text" required />
                        <p class="description">Your Little Green Light API endpoint URL</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgl_api_key">API Key</label></th>
                    <td>
                        <input type="password" name="lgl_api_key" id="lgl_api_key" 
                               value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" 
                               class="regular-text" required />
                        <p class="description">Your API authentication key</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save API Settings'); ?>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render membership settings form
     */
    private function renderMembershipSettingsForm(array $settings, string $nonce): string {
        $levels = $settings['membership_levels'] ?? [];
        
        ob_start();
        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('lgl_membership_settings', '_wpnonce'); ?>
            <input type="hidden" name="action" value="lgl_save_membership_settings" />
            
            <p>Configure your membership levels and their LGL mappings.</p>
            
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Level Name</th>
                        <th>LGL Level ID</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($levels)): ?>
                        <tr><td colspan="3"><em>No membership levels configured. Add them below.</em></td></tr>
                    <?php else: ?>
                        <?php foreach ($levels as $level): ?>
                            <tr>
                                <td><?php echo esc_html($level['level_name'] ?? ''); ?></td>
                                <td><?php echo esc_html($level['lgl_membership_level_id'] ?? ''); ?></td>
                                <td>$<?php echo number_format($level['price'] ?? 0, 2); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php submit_button('Import from LGL'); ?>
        </form>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render advanced settings form
     */
    private function renderAdvancedSettingsForm(array $settings, string $nonce): string {
        ob_start();
        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('lgl_debug_settings', '_wpnonce'); ?>
            <input type="hidden" name="action" value="lgl_save_debug_settings" />
            
            <table class="form-table">
                <tr>
                    <th>Debug Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lgl_debug_mode" value="1" 
                                   <?php checked(!empty($settings['debug_mode'])); ?> />
                            Enable debug logging
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Test Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lgl_test_mode" value="1" 
                                   <?php checked(!empty($settings['test_mode'])); ?> />
                            Enable test mode (no live API calls)
                        </label>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Advanced Settings'); ?>
        </form>
        <?php
        return ob_get_clean();
    }
}
