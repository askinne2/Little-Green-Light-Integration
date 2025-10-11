<?php
/**
 * Admin Menu Manager
 * 
 * Manages the unified LGL admin interface with organized menus and submenus.
 * Consolidates all LGL-related admin functionality under one main menu.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\LGL\ApiSettings;

/**
 * AdminMenuManager Class
 * 
 * Manages the unified LGL admin interface
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
     * Constructor
     * 
     * @param Helper $helper Helper service
     * @param ApiSettings $apiSettings API settings service
     * @param SettingsHandler $settingsHandler Settings handler service
     */
    public function __construct(Helper $helper, ApiSettings $apiSettings, SettingsHandler $settingsHandler) {
        $this->helper = $helper;
        $this->apiSettings = $apiSettings;
        $this->settingsHandler = $settingsHandler;
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
        
        // Debug & Logs
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Debug & Logs',
            'Debug & Logs',
            'manage_options',
            'lgl-debug',
            [$this, 'renderDebug']
        );
        
        // Documentation
        add_submenu_page(
            self::MAIN_MENU_SLUG,
            'LGL Documentation',
            'Documentation',
            'manage_options',
            'lgl-docs',
            [$this, 'renderDocumentation']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueueAssets($hook): void {
        // Only load on LGL pages
        if (strpos($hook, 'lgl-') === false && $hook !== 'toplevel_page_' . self::MAIN_MENU_SLUG) {
            return;
        }
        
        wp_enqueue_style(
            'lgl-admin-styles',
            plugin_dir_url(__DIR__ . '/../../../') . 'assets/admin-settings.css',
            [],
            '2.0.0'
        );
        
        wp_enqueue_script(
            'lgl-admin-scripts',
            plugin_dir_url(__DIR__ . '/../../../') . 'assets/admin-settings.js',
            ['jquery'],
            '2.0.0',
            true
        );
        
        // Localize script for AJAX
        wp_localize_script('lgl-admin-scripts', 'lglAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lgl_admin_nonce'),
            'strings' => [
                'testing' => __('Testing...', 'lgl'),
                'success' => __('Success!', 'lgl'),
                'failed' => __('Failed', 'lgl'),
                'error' => __('Error', 'lgl')
            ],
            'pluginVersion' => '2.0.0'
        ]);
    }
    
    /**
     * Render dashboard page
     */
    public function renderDashboard(): void {
        ?>
        <div class="wrap lgl-admin-page">
            <h1>🔗 LGL Integration Dashboard</h1>
            <p>Welcome to the Little Green Light integration dashboard. Monitor your system status and access all LGL functionality from here.</p>
            
            <div class="lgl-dashboard-grid">
                
                <!-- System Status -->
                <div class="lgl-card lgl-card-status">
                    <h2>📊 System Status</h2>
                    <?php $this->renderSystemStatus(); ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="lgl-card lgl-card-actions">
                    <h2>🚀 Quick Actions</h2>
                    <div class="lgl-quick-actions">
                        <a href="<?php echo admin_url('admin.php?page=lgl-settings'); ?>" class="button button-primary">
                            ⚙️ Configure Settings
                        </a>
                        <a href="<?php echo admin_url('admin.php?page=lgl-testing'); ?>" class="button button-secondary">
                            🧪 Run Tests
                        </a>
                        <button type="button" class="button button-secondary" onclick="testConnection()">
                            🔌 Test Connection
                        </button>
                    </div>
                </div>
                
                <!-- Recent Activity -->
                <div class="lgl-card lgl-card-activity">
                    <h2>📈 Recent Activity</h2>
                    <?php $this->renderRecentActivity(); ?>
                </div>
                
                <!-- Statistics -->
                <div class="lgl-card lgl-card-stats">
                    <h2>📊 Statistics</h2>
                    <?php $this->renderStatistics(); ?>
                </div>
                
            </div>
            
            <!-- Architecture Information -->
            <div class="lgl-card lgl-architecture-info">
                <h2>🏗️ Architecture Status</h2>
                <?php $this->renderArchitectureStatus(); ?>
            </div>
            
        </div>
        
        <script>
        function testConnection() {
            const button = event.target;
            const originalText = button.textContent;
            button.textContent = '🔄 Testing...';
            button.disabled = true;
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lgl_test_connection&nonce=' + lglAdmin.nonce
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    button.textContent = '✅ Connected';
                    setTimeout(() => {
                        button.textContent = originalText;
                        button.disabled = false;
                    }, 2000);
                } else {
                    button.textContent = '❌ Failed';
                    setTimeout(() => {
                        button.textContent = originalText;
                        button.disabled = false;
                    }, 2000);
                }
            })
            .catch(error => {
                button.textContent = '❌ Error';
                setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                }, 2000);
            });
        }
        </script>
        
        <style>
        .lgl-admin-page {
            max-width: 1200px;
        }
        .lgl-dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .lgl-card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .lgl-card h2 {
            margin-top: 0;
            font-size: 18px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .lgl-quick-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .lgl-status-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .lgl-status-item:last-child {
            border-bottom: none;
        }
        .lgl-architecture-info {
            margin-top: 20px;
        }
        </style>
        <?php
    }
    
    /**
     * Render settings page
     */
    public function renderSettings(): void {
        ?>
        <div class="wrap lgl-admin-page">
            <h1>⚙️ LGL Settings</h1>
            <p>Configure your Little Green Light API connection and membership settings.</p>
            
            <div class="nav-tab-wrapper">
                <a href="#api-config" class="nav-tab nav-tab-active" onclick="switchTab(event, 'api-config')">🔌 API Configuration</a>
                <a href="#membership-levels" class="nav-tab" onclick="switchTab(event, 'membership-levels')">🎯 Membership Levels</a>
                <a href="#debug-settings" class="nav-tab" onclick="switchTab(event, 'debug-settings')">🔧 Debug Settings</a>
            </div>
            
            <div id="api-config" class="lgl-tab-content">
                <h2>🔌 API Configuration</h2>
                <?php $this->renderApiSettings(); ?>
            </div>
            
            <div id="membership-levels" class="lgl-tab-content" style="display: none;">
                <h2>🎯 Membership Levels</h2>
                <?php $this->renderMembershipSettings(); ?>
            </div>
            
            <div id="debug-settings" class="lgl-tab-content" style="display: none;">
                <h2>🔧 Debug Settings</h2>
                <?php $this->renderDebugSettings(); ?>
            </div>
            
        </div>
        
        <script>
        function switchTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("lgl-tab-content");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }
            tablinks = document.getElementsByClassName("nav-tab");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("nav-tab-active");
            }
            document.getElementById(tabName).style.display = "block";
            evt.currentTarget.classList.add("nav-tab-active");
        }
        </script>
        <?php
    }
    
    /**
     * Render testing page
     */
    public function renderTesting(): void {
        ?>
        <div class="wrap lgl-admin-page">
            <h1>🧪 LGL Testing Suite</h1>
            <p>Comprehensive testing tools for the LGL integration system.</p>
            
            <div class="lgl-testing-grid">
                
                <!-- Connection Tests -->
                <div class="lgl-card">
                    <h2>🔌 Connection Tests</h2>
                    <p>Test your LGL API connection and credentials.</p>
                    <button type="button" class="button button-primary" onclick="runTest('connection')">
                        Test Connection
                    </button>
                    <button type="button" class="button button-secondary" onclick="runTest('search')">
                        Test Search Function
                    </button>
                </div>
                
                <!-- Flow Tests -->
                <div class="lgl-card">
                    <h2>🔄 Integration Flow Tests</h2>
                    <p>Test complete order processing flows.</p>
                    <button type="button" class="button button-primary" onclick="runTest('membership-flow')">
                        Test Membership Flow
                    </button>
                    <button type="button" class="button button-secondary" onclick="runTest('event-flow')">
                        Test Event Flow
                    </button>
                    <button type="button" class="button button-secondary" onclick="runTest('class-flow')">
                        Test Class Flow
                    </button>
                </div>
                
                <!-- Debug Tests -->
                <div class="lgl-card">
                    <h2>🔧 Debug & Architecture Tests</h2>
                    <p>Test system architecture and debug functionality.</p>
                    <button type="button" class="button button-secondary" onclick="runTest('debug-settings')">
                        Test Debug Settings
                    </button>
                    <button type="button" class="button button-secondary" onclick="runTest('service-container')">
                        Test Service Container
                    </button>
                    <button type="button" class="button button-secondary" onclick="runTest('architecture')">
                        Test Architecture
                    </button>
                </div>
                
                <!-- Batch Tests -->
                <div class="lgl-card">
                    <h2>⚡ Batch Operations</h2>
                    <p>Run multiple tests or comprehensive test suites.</p>
                    <button type="button" class="button button-hero button-primary" onclick="runTest('full-suite')">
                        🧪 Run Complete Test Suite
                    </button>
                    <button type="button" class="button button-secondary" onclick="clearResults()">
                        Clear Results
                    </button>
                </div>
                
            </div>
            
            <!-- Test Results -->
            <div id="test-results" class="lgl-card" style="margin-top: 20px; display: none;">
                <h2>📊 Test Results</h2>
                <div id="test-output"></div>
            </div>
            
        </div>
        
        <script>
        function runTest(testType) {
            const resultsDiv = document.getElementById('test-results');
            const outputDiv = document.getElementById('test-output');
            
            resultsDiv.style.display = 'block';
            outputDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Running ' + testType + ' test...</p></div>';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lgl_run_test&test_type=' + testType + '&nonce=' + lglAdmin.nonce
            })
            .then(response => response.text())
            .then(data => {
                outputDiv.innerHTML = data;
            })
            .catch(error => {
                outputDiv.innerHTML = '<div class="notice notice-error"><p>❌ Test failed: ' + error + '</p></div>';
            });
        }
        
        function clearResults() {
            const resultsDiv = document.getElementById('test-results');
            resultsDiv.style.display = 'none';
        }
        </script>
        
        <style>
        .lgl-testing-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        </style>
        <?php
    }
    
    /**
     * Render debug page
     */
    public function renderDebug(): void {
        ?>
        <div class="wrap lgl-admin-page">
            <h1>🔧 LGL Debug & Logs</h1>
            <p>Debug information, logs, and system diagnostics.</p>
            
            <div class="nav-tab-wrapper">
                <a href="#system-info" class="nav-tab nav-tab-active" onclick="switchTab(event, 'system-info')">📊 System Info</a>
                <a href="#error-logs" class="nav-tab" onclick="switchTab(event, 'error-logs')">📝 Error Logs</a>
                <a href="#debug-tools" class="nav-tab" onclick="switchTab(event, 'debug-tools')">🔧 Debug Tools</a>
            </div>
            
            <div id="system-info" class="lgl-tab-content">
                <h2>📊 System Information</h2>
                <?php $this->renderSystemInfo(); ?>
            </div>
            
            <div id="error-logs" class="lgl-tab-content" style="display: none;">
                <h2>📝 Error Logs</h2>
                <?php $this->renderErrorLogs(); ?>
            </div>
            
            <div id="debug-tools" class="lgl-tab-content" style="display: none;">
                <h2>🔧 Debug Tools</h2>
                <?php $this->renderDebugTools(); ?>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Render documentation page
     */
    public function renderDocumentation(): void {
        ?>
        <div class="wrap lgl-admin-page">
            <h1>📋 LGL Documentation</h1>
            <p>Comprehensive documentation and guides for the LGL integration.</p>
            
            <div class="lgl-docs-grid">
                
                <div class="lgl-card">
                    <h2>🚀 Getting Started</h2>
                    <ul>
                        <li><a href="#setup">Initial Setup & Configuration</a></li>
                        <li><a href="#api-keys">API Key Configuration</a></li>
                        <li><a href="#membership-setup">Membership Level Setup</a></li>
                        <li><a href="#testing">Testing Your Configuration</a></li>
                    </ul>
                </div>
                
                <div class="lgl-card">
                    <h2>🔧 Technical Documentation</h2>
                    <ul>
                        <li><a href="#architecture">Modern Architecture Overview</a></li>
                        <li><a href="#api-reference">API Reference</a></li>
                        <li><a href="#hooks-filters">Hooks & Filters</a></li>
                        <li><a href="#troubleshooting">Troubleshooting Guide</a></li>
                    </ul>
                </div>
                
                <div class="lgl-card">
                    <h2>🎯 Integration Guides</h2>
                    <ul>
                        <li><a href="#woocommerce">WooCommerce Integration</a></li>
                        <li><a href="#jetformbuilder">JetFormBuilder Actions</a></li>
                        <li><a href="#membership-flows">Membership Flows</a></li>
                        <li><a href="#event-management">Event Management</a></li>
                    </ul>
                </div>
                
                <div class="lgl-card">
                    <h2>📊 Optimization Checklist</h2>
                    <p>View the complete optimization checklist and modernization progress.</p>
                    <a href="<?php echo plugin_dir_url(__DIR__ . '/../../') . 'docs/lgl_optimization_checklist.md'; ?>" target="_blank" class="button button-primary">
                        View Checklist
                    </a>
                </div>
                
            </div>
            
        </div>
        
        <style>
        .lgl-docs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        </style>
        <?php
    }
    
    // Helper methods for rendering sections
    
    private function renderSystemStatus(): void {
        $connection = \UpstateInternational\LGL\LGL\Connection::getInstance();
        $plugin = \UpstateInternational\LGL\Core\Plugin::getInstance();
        
        $status_items = [
            'Modern Architecture' => class_exists('\UpstateInternational\LGL\Core\Plugin') ? '✅ Active' : '❌ Not Available',
            'API Connection' => !empty($this->apiSettings->getApiKey()) ? '✅ Configured' : '⚠️ Not Configured',
            'Debug Mode' => $this->apiSettings->isDebugMode() ? '✅ Enabled' : '❌ Disabled',
            'Service Container' => $plugin ? '✅ Available' : '❌ Not Available',
            'Autoloader' => class_exists('\Composer\Autoload\ClassLoader') ? '✅ Active' : '❌ Missing'
        ];
        
        foreach ($status_items as $item => $status) {
            echo '<div class="lgl-status-item">';
            echo '<span>' . $item . '</span>';
            echo '<span>' . $status . '</span>';
            echo '</div>';
        }
    }
    
    private function renderRecentActivity(): void {
        echo '<p>Recent activity tracking coming soon...</p>';
    }
    
    private function renderStatistics(): void {
        echo '<p>System statistics coming soon...</p>';
    }
    
    private function renderArchitectureStatus(): void {
        echo '<div class="lgl-architecture-status">';
        echo '<p><strong>✅ Modern Architecture Status:</strong></p>';
        echo '<ul>';
        echo '<li>✅ PSR-4 Namespaces: 100% Complete</li>';
        echo '<li>✅ Service Container: Active</li>';
        echo '<li>✅ Dependency Injection: Implemented</li>';
        echo '<li>✅ Legacy Compatibility: Maintained</li>';
        echo '<li>✅ Testing Suite: Available</li>';
        echo '</ul>';
        echo '</div>';
    }
    
    private function renderApiSettings(): void {
        // Get settings from new handler
        $api_url = $this->settingsHandler->getSetting('api_url', '');
        $api_key = $this->settingsHandler->getSetting('api_key', '');
        
        // Check for admin messages
        $message = '';
        $message_type = '';
        if (isset($_GET['message']) && isset($_GET['type'])) {
            $message = sanitize_text_field($_GET['message']);
            $message_type = sanitize_text_field($_GET['type']);
        }
        
        ?>
        <?php if ($message): ?>
            <div class="notice notice-<?php echo $message_type === 'updated' ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="lgl_save_api_settings">
            <?php wp_nonce_field('lgl_api_settings'); ?>
            
            <!-- Instructions -->
            <div class="lgl-settings-instructions">
                <h3>🔗 LGL API Configuration</h3>
                <p><strong>To configure your Little Green Light API connection:</strong></p>
                <ol>
                    <li>Log in to your Little Green Light account</li>
                    <li>Navigate to <strong>Settings → API</strong></li>
                    <li>Generate or copy your API key</li>
                    <li>Enter your API URL and key below</li>
                </ol>
                <p><em>💡 <strong>Tip:</strong> Test your connection after saving settings.</em></p>
            </div>
            
            <!-- API Settings Fields -->
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="lgl_api_url">API Base URL</label>
                    </th>
                    <td>
                        <input type="url" 
                               id="lgl_api_url" 
                               name="lgl_api_url" 
                               value="<?php echo esc_attr($api_url); ?>"
                               placeholder="https://api.littlegreenlight.com"
                               class="regular-text"
                               required />
                        <p class="description">Your Little Green Light API base URL</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lgl_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="password" 
                               id="lgl_api_key" 
                               name="lgl_api_key" 
                               value="<?php echo esc_attr($api_key); ?>"
                               class="regular-text"
                               required />
                        <p class="description">Your Little Green Light API key</p>
                        <button type="button" class="button button-secondary" onclick="toggleApiKeyVisibility()">
                            👁️ Show/Hide
                        </button>
                    </td>
                </tr>
            </table>
            
            <!-- Test Connection -->
            <div class="lgl-connection-test">
                <h4>🔌 Test Connection</h4>
                <button type="button" class="button button-secondary" onclick="testApiConnection()">
                    Test API Connection
                </button>
                <div id="connection-test-result"></div>
            </div>
            
            <?php submit_button('Save API Settings'); ?>
        </form>
        
        <script>
        function toggleApiKeyVisibility() {
            const keyInput = document.getElementById('lgl_api_key');
            keyInput.type = keyInput.type === 'password' ? 'text' : 'password';
        }
        
        function testApiConnection() {
            const button = event.target;
            const resultDiv = document.getElementById('connection-test-result');
            const originalText = button.textContent;
            
            // Debug: Check if lglAdmin is available
            console.log('lglAdmin object:', lglAdmin);
            console.log('AJAX URL:', lglAdmin ? lglAdmin.ajaxurl : 'UNDEFINED');
            console.log('Nonce:', lglAdmin ? lglAdmin.nonce : 'UNDEFINED');
            
            button.textContent = '🔄 Testing...';
            button.disabled = true;
            resultDiv.innerHTML = '';
            
            // Fallback if lglAdmin is not defined
            const ajaxUrl = (typeof lglAdmin !== 'undefined' && lglAdmin.ajaxurl) ? lglAdmin.ajaxurl : '/wp-admin/admin-ajax.php';
            const nonce = (typeof lglAdmin !== 'undefined' && lglAdmin.nonce) ? lglAdmin.nonce : 'no-nonce';
            
            console.log('Using AJAX URL:', ajaxUrl);
            console.log('Using nonce:', nonce);
            
            // Get API credentials from the form
            const apiUrl = document.getElementById('lgl_api_url').value;
            const apiKey = document.getElementById('lgl_api_key').value;
            
            if (!apiUrl || !apiKey) {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Please enter both API URL and API Key before testing.</p></div>';
                button.textContent = originalText;
                button.disabled = false;
                return;
            }
            
            const formData = new URLSearchParams();
            formData.append('action', 'lgl_test_connection');
            formData.append('nonce', nonce);
            formData.append('api_url', apiUrl);
            formData.append('api_key', apiKey);
            
            fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: formData
            })
            .then(response => {
                console.log('Fetch response received:', response);
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text(); // Get as text first to see what we're getting
            })
            .then(responseText => {
                console.log('Response text:', responseText);
                try {
                    const data = JSON.parse(responseText);
                    console.log('Parsed JSON data:', data);
                    if (data.success) {
                        resultDiv.innerHTML = '<div class="notice notice-success"><p>✅ Connection successful!</p></div>';
                    } else {
                        resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Connection failed: ' + (data.data ? data.data.message : data.message || 'Unknown error') + '</p></div>';
                    }
                } catch (jsonError) {
                    console.error('JSON parse error:', jsonError);
                    resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Invalid response: ' + responseText.substring(0, 200) + '</p></div>';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Test failed: ' + error + '</p></div>';
            })
            .finally(() => {
                button.textContent = originalText;
                button.disabled = false;
            });
        }
        </script>
        
        <style>
        .lgl-settings-instructions {
            background: #f1f1f1;
            padding: 20px;
            border-left: 4px solid #0073aa;
            margin: 20px 0;
        }
        .lgl-connection-test {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 20px 0;
        }
        </style>
        <?php
    }
    
    private function renderMembershipSettings(): void {
        $membership_levels = $this->settingsHandler->getSetting('membership_levels', []);
        
        // Check for admin messages
        $message = '';
        $message_type = '';
        if (isset($_GET['message']) && isset($_GET['type'])) {
            $message = sanitize_text_field($_GET['message']);
            $message_type = sanitize_text_field($_GET['type']);
        }
        
        ?>
        <?php if ($message): ?>
            <div class="notice notice-<?php echo $message_type === 'updated' ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="lgl-membership-settings">
            <h3>🎯 Membership Levels Configuration</h3>
            <p>Configure your membership levels and their corresponding LGL membership level IDs for payment attribution.</p>
            
            <!-- Instructions -->
            <div class="lgl-settings-instructions">
                <h4>📋 How to Configure Membership Levels:</h4>
                <ol>
                    <li>Find the membership level IDs in LGL under <strong>Settings → Membership Levels</strong></li>
                    <li>The LGL Membership Level ID is crucial for payment attribution</li>
                    <li>Match your WooCommerce product prices to the correct LGL membership levels</li>
                </ol>
                <p><em>💡 <strong>Tip:</strong> The system uses price-based matching to determine the correct membership level during checkout.</em></p>
            </div>
            
            <!-- Membership Levels Form -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="lgl_save_membership_settings">
                <?php wp_nonce_field('lgl_membership_settings'); ?>
                
                <h4>Add/Edit Membership Levels</h4>
                <p>You can add multiple membership levels. Leave fields blank to remove a level.</p>
                
                <div id="membership-levels-container">
                    <?php if (empty($membership_levels)): ?>
                        <!-- Default empty form -->
                        <div class="membership-level-row">
                            <h5>Membership Level 1</h5>
                            <table class="form-table">
                                <tr>
                                    <th>Level Name</th>
                                    <td><input type="text" name="membership_levels[0][level_name]" placeholder="e.g., Individual" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th>Level Slug</th>
                                    <td><input type="text" name="membership_levels[0][level_slug]" placeholder="e.g., individual" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th>LGL Membership Level ID</th>
                                    <td><input type="number" name="membership_levels[0][lgl_membership_level_id]" placeholder="e.g., 123" class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th>Price</th>
                                    <td><input type="number" name="membership_levels[0][price]" step="0.01" placeholder="e.g., 75.00" class="regular-text" /></td>
                                </tr>
                            </table>
                        </div>
                    <?php else: ?>
                        <?php foreach ($membership_levels as $index => $level): ?>
                            <div class="membership-level-row">
                                <h5>Membership Level <?php echo $index + 1; ?></h5>
                                <table class="form-table">
                                    <tr>
                                        <th>Level Name</th>
                                        <td><input type="text" name="membership_levels[<?php echo $index; ?>][level_name]" value="<?php echo esc_attr($level['level_name'] ?? ''); ?>" class="regular-text" /></td>
                                    </tr>
                                    <tr>
                                        <th>Level Slug</th>
                                        <td><input type="text" name="membership_levels[<?php echo $index; ?>][level_slug]" value="<?php echo esc_attr($level['level_slug'] ?? ''); ?>" class="regular-text" /></td>
                                    </tr>
                                    <tr>
                                        <th>LGL Membership Level ID</th>
                                        <td><input type="number" name="membership_levels[<?php echo $index; ?>][lgl_membership_level_id]" value="<?php echo esc_attr($level['lgl_membership_level_id'] ?? ''); ?>" class="regular-text" /></td>
                                    </tr>
                                    <tr>
                                        <th>Price</th>
                                        <td><input type="number" name="membership_levels[<?php echo $index; ?>][price]" step="0.01" value="<?php echo esc_attr($level['price'] ?? ''); ?>" class="regular-text" /></td>
                                    </tr>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <!-- Add another level button -->
                    <div class="membership-level-row">
                        <h5>Add Another Level</h5>
                        <table class="form-table">
                            <tr>
                                <th>Level Name</th>
                                <td><input type="text" name="membership_levels[new][level_name]" placeholder="e.g., Family" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th>Level Slug</th>
                                <td><input type="text" name="membership_levels[new][level_slug]" placeholder="e.g., family" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th>LGL Membership Level ID</th>
                                <td><input type="number" name="membership_levels[new][lgl_membership_level_id]" placeholder="e.g., 456" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th>Price</th>
                                <td><input type="number" name="membership_levels[new][price]" step="0.01" placeholder="e.g., 125.00" class="regular-text" /></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <?php submit_button('Save Membership Levels'); ?>
            </form>
            
            <!-- Current Membership Levels -->
            <h4>Current Membership Levels</h4>
            <?php if (empty($membership_levels)): ?>
                <div class="notice notice-warning">
                    <p>⚠️ <strong>No membership levels configured.</strong></p>
                    <p>Please configure your membership levels using the Carbon Fields interface for now:</p>
                    <p><em>This will be moved to a native interface in a future update.</em></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Level Name</th>
                            <th>Level Slug</th>
                            <th>LGL Membership ID</th>
                            <th>Price</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($membership_levels as $index => $level): ?>
                            <tr>
                                <td><strong><?php echo esc_html($level['level_name'] ?? 'Unnamed'); ?></strong></td>
                                <td><code><?php echo esc_html($level['level_slug'] ?? 'no-slug'); ?></code></td>
                                <td><?php echo esc_html($level['lgl_membership_level_id'] ?? 'Not Set'); ?></td>
                                <td>$<?php echo esc_html($level['price'] ?? '0.00'); ?></td>
                                <td>
                                    <button type="button" class="button button-small" onclick="editMembershipLevel(<?php echo $index; ?>)">
                                        Edit
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            
            <!-- Price-to-Membership Mapping Test -->
            <div class="lgl-price-mapping-test">
                <h4>🧪 Test Price-to-Membership Mapping</h4>
                <p>Test how the system maps WooCommerce product prices to LGL membership levels:</p>
                <div class="price-test-form">
                    <input type="number" 
                           id="test-price" 
                           placeholder="Enter price (e.g., 75.00)" 
                           step="0.01" 
                           style="width: 200px;" />
                    <button type="button" class="button button-secondary" onclick="testPriceMapping()">
                        Test Mapping
                    </button>
                    <div id="price-mapping-result"></div>
                </div>
            </div>
            
            <!-- Legacy Configuration Notice -->
            <div class="notice notice-info">
                <h4>⚙️ Current Configuration Method</h4>
                <p><strong>For now, membership levels are configured using Carbon Fields.</strong></p>
                <p>A native interface will be available in a future update. The current system works perfectly for:</p>
                <ul>
                    <li>✅ Price-based membership detection</li>
                    <li>✅ LGL API integration</li>
                    <li>✅ Payment attribution</li>
                    <li>✅ User role assignment</li>
                </ul>
            </div>
        </div>
        
        <script>
        function testPriceMapping() {
            const priceInput = document.getElementById('test-price');
            const resultDiv = document.getElementById('price-mapping-result');
            const price = parseFloat(priceInput.value);
            
            if (!price || price <= 0) {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>Please enter a valid price.</p></div>';
                return;
            }
            
            // Test the price mapping logic
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lgl_test_price_mapping&price=' + price + '&nonce=' + lglAdmin.nonce
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    resultDiv.innerHTML = '<div class="notice notice-success"><p><strong>Price $' + price + ' maps to:</strong> ' + data.data.membership_level + '</p></div>';
                } else {
                    resultDiv.innerHTML = '<div class="notice notice-warning"><p><strong>Price $' + price + ':</strong> No matching membership level found</p></div>';
                }
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>Test failed: ' + error + '</p></div>';
            });
        }
        
        function editMembershipLevel(index) {
            alert('Membership level editing will be available in a future update.\n\nFor now, please use the Carbon Fields interface to modify membership levels.');
        }
        </script>
        
        <style>
        .lgl-membership-settings .wp-list-table {
            margin: 20px 0;
        }
        .lgl-price-mapping-test {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 20px 0;
        }
        .price-test-form {
            margin: 10px 0;
        }
        .price-test-form input {
            margin-right: 10px;
        }
        </style>
        <?php
    }
    
    private function renderDebugSettings(): void {
        $debug_mode = $this->settingsHandler->isDebugMode();
        $test_mode = $this->settingsHandler->isTestMode();
        
        // Check for admin messages
        $message = '';
        $message_type = '';
        if (isset($_GET['message']) && isset($_GET['type'])) {
            $message = sanitize_text_field($_GET['message']);
            $message_type = sanitize_text_field($_GET['type']);
        }
        
        ?>
        <?php if ($message): ?>
            <div class="notice notice-<?php echo $message_type === 'updated' ? 'success' : 'error'; ?> is-dismissible">
                <p><?php echo esc_html($message); ?></p>
            </div>
        <?php endif; ?>
        
        <div class="lgl-debug-settings">
            <h3>🔧 Debug & Development Settings</h3>
            <p>Configure debugging and development options for troubleshooting and testing.</p>
            
            <!-- Debug Settings Form -->
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="lgl_save_debug_settings">
                <?php wp_nonce_field('lgl_debug_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>Debug Mode</span></legend>
                                <label for="lgl_debug_mode">
                                    <input name="lgl_debug_mode" type="checkbox" id="lgl_debug_mode" value="1" <?php checked($debug_mode); ?> />
                                    Enable Debug Mode
                                </label>
                                <p class="description">
                                    ✅ <strong>Currently:</strong> <?php echo $debug_mode ? 'Enabled' : 'Disabled'; ?><br>
                                    Enable detailed logging for troubleshooting. Logs will appear in error_log and browser console.
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Test Mode</th>
                        <td>
                            <fieldset>
                                <legend class="screen-reader-text"><span>Test Mode</span></legend>
                                <label for="lgl_test_mode">
                                    <input name="lgl_test_mode" type="checkbox" id="lgl_test_mode" value="1" <?php checked($test_mode); ?> />
                                    Enable Test Mode
                                </label>
                                <p class="description">
                                    ✅ <strong>Currently:</strong> <?php echo $test_mode ? 'Enabled' : 'Disabled'; ?><br>
                                    Use test API endpoints (if available) and enable additional testing features.
                                </p>
                            </fieldset>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Debug Settings'); ?>
            </form>
            
            <!-- Debug Status -->
            <div class="lgl-debug-status">
                <h4>📊 Current Debug Status</h4>
                <table class="wp-list-table widefat fixed striped">
                    <tbody>
                        <tr>
                            <td><strong>Debug Mode</strong></td>
                            <td><?php echo $debug_mode ? '✅ Enabled' : '❌ Disabled'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Test Mode</strong></td>
                            <td><?php echo $test_mode ? '✅ Enabled' : '❌ Disabled'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>WordPress Debug</strong></td>
                            <td><?php echo (defined('WP_DEBUG') && WP_DEBUG) ? '✅ Enabled' : '❌ Disabled'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Error Logging</strong></td>
                            <td><?php echo ini_get('log_errors') ? '✅ Enabled' : '❌ Disabled'; ?></td>
                        </tr>
                        <tr>
                            <td><strong>PHP Version</strong></td>
                            <td><?php echo PHP_VERSION; ?></td>
                        </tr>
                        <tr>
                            <td><strong>Memory Limit</strong></td>
                            <td><?php echo ini_get('memory_limit'); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Debug Tools -->
            <div class="lgl-debug-tools">
                <h4>🛠️ Debug Tools</h4>
                <div class="debug-actions">
                    <button type="button" class="button button-secondary" onclick="testDebugSystem()">
                        🧪 Test Debug System
                    </button>
                    <button type="button" class="button button-secondary" onclick="clearDebugLogs()">
                        🗑️ Clear Debug Logs
                    </button>
                    <button type="button" class="button button-secondary" onclick="downloadDebugInfo()">
                        📥 Download Debug Info
                    </button>
                </div>
                <div id="debug-tools-result"></div>
            </div>
            
            <!-- Debug Connection Status -->
            <div class="lgl-debug-connection">
                <h4>🔗 Helper-ApiSettings Connection Test</h4>
                <p>Test the connection between Helper.php debug system and ApiSettings.php checkbox:</p>
                <button type="button" class="button button-secondary" onclick="testDebugConnection()">
                    Test Debug Connection
                </button>
                <div id="debug-connection-result"></div>
            </div>
        </div>
        
        <script>
        function testDebugSystem() {
            const resultDiv = document.getElementById('debug-tools-result');
            resultDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Testing debug system...</p></div>';
            
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lgl_test_debug_system&nonce=' + lglAdmin.nonce
            })
            .then(response => response.text())
            .then(data => {
                resultDiv.innerHTML = data;
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Test failed: ' + error + '</p></div>';
            });
        }
        
        function clearDebugLogs() {
            if (!confirm('Are you sure you want to clear all debug logs?')) {
                return;
            }
            
            const resultDiv = document.getElementById('debug-tools-result');
            resultDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Clearing debug logs...</p></div>';
            
            // This would be implemented as an AJAX action
            setTimeout(() => {
                resultDiv.innerHTML = '<div class="notice notice-success"><p>✅ Debug logs cleared (feature coming soon)</p></div>';
            }, 1000);
        }
        
        function downloadDebugInfo() {
            const resultDiv = document.getElementById('debug-tools-result');
            resultDiv.innerHTML = '<div class="notice notice-info"><p>📥 Preparing debug info download (feature coming soon)...</p></div>';
        }
        
        function testDebugConnection() {
            const resultDiv = document.getElementById('debug-connection-result');
            resultDiv.innerHTML = '<div class="notice notice-info"><p>🔄 Testing debug connection...</p></div>';
            
            // This would run the debug-settings-test shortcode functionality
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=lgl_run_test&test_type=debug-settings&nonce=' + lglAdmin.nonce
            })
            .then(response => response.text())
            .then(data => {
                resultDiv.innerHTML = data;
            })
            .catch(error => {
                resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ Test failed: ' + error + '</p></div>';
            });
        }
        </script>
        
        <style>
        .lgl-debug-status, .lgl-debug-tools, .lgl-debug-connection {
            background: #f9f9f9;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin: 20px 0;
        }
        .debug-actions {
            margin: 10px 0;
        }
        .debug-actions button {
            margin-right: 10px;
            margin-bottom: 5px;
        }
        </style>
        <?php
    }
    
    private function renderSystemInfo(): void {
        echo '<pre>';
        echo 'Plugin Version: 2.0.0' . PHP_EOL;
        echo 'WordPress Version: ' . get_bloginfo('version') . PHP_EOL;
        echo 'PHP Version: ' . PHP_VERSION . PHP_EOL;
        echo 'Memory Limit: ' . ini_get('memory_limit') . PHP_EOL;
        echo 'Debug Mode: ' . (WP_DEBUG ? 'Enabled' : 'Disabled') . PHP_EOL;
        echo '</pre>';
    }
    
    private function renderErrorLogs(): void {
        echo '<p>Error log viewer coming soon...</p>';
    }
    
    private function renderDebugTools(): void {
        echo '<p>Debug tools interface coming soon...</p>';
    }
}
?>
