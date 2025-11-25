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

if (!function_exists('lgl_log')) {
    /**
     * Log a message to the LGL debug log
     * 
     * Convenience function that delegates to Helper::debug().
     * Only logs when debug mode is enabled in LGL settings.
     * 
     * @param string $message Log message
     * @param mixed $data Optional data to log (arrays, objects, etc.)
     * @return void
     */
    function lgl_log(string $message, $data = null): void {
        try {
            $helper = \UpstateInternational\LGL\LGL\Helper::getInstance();
            $helper->debug($message, $data);
        } catch (\Exception $e) {
            // Fallback: Only log errors, not debug messages
            // This prevents PHP error log spam if Helper is unavailable
            if (strpos(strtolower($message), 'error') !== false || strpos(strtolower($message), 'fail') !== false) {
                error_log("LGL Log [ERROR]: {$message}");
            }
        }
    }
}

if (!function_exists('lgl_render_environment_notice')) {
    /**
     * Render environment notice showing current environment and database name
     * 
     * @return string HTML for environment notice
     */
    function lgl_render_environment_notice(): string {
        if (!function_exists('lgl_get_container')) {
            return '';
        }
        
        try {
            $container = lgl_get_container();
            if (!$container->has('admin.settings_manager')) {
                return '';
            }
            
            $settingsManager = $container->get('admin.settings_manager');
            $environment = $settingsManager->getEnvironment();
            $api_url = $settingsManager->getApiUrlForEnvironment();
            
            // Extract database name from API URL
            // LGL URLs can be: https://[db].littlegreenlight.com/api/v1 or https://api.littlegreenlight.com/api/v1
            $database_name = 'Unknown';
            if (!empty($api_url)) {
                // Try to extract subdomain (database name)
                if (preg_match('/https?:\/\/([^\.]+)\.littlegreenlight\.com/', $api_url, $matches)) {
                    $database_name = $matches[1];
                } elseif (strpos($api_url, 'api.littlegreenlight.com') !== false) {
                    // Standard API URL - database name might be in API key or we can't determine it
                    $database_name = 'Standard API';
                } else {
                    // Extract hostname as fallback
                    $parsed = parse_url($api_url);
                    if (isset($parsed['host'])) {
                        $database_name = $parsed['host'];
                    }
                }
            }
            
            $env_label = ucfirst($environment);
            $env_color = $environment === 'dev' ? '#d63638' : '#00a32a';
            
            return sprintf(
                '<div id="lgl-environment-notice" class="notice notice-info" style="border-left-color: %s; margin: 15px 0; display: block !important; visibility: visible !important; opacity: 1 !important;">
                    <p style="margin: 0.5em 0;">
                        <strong>🌐 Current Environment:</strong> 
                        <span style="color: %s; font-weight: bold;">%s</span>
                    </p>
                </div>',
                esc_attr($env_color),
                esc_attr($env_color),
                esc_html($env_label),
                esc_html($database_name)
            );
        } catch (\Exception $e) {
            // Silently fail if container not available
            return '';
        }
    }
}

if (!function_exists('lgl_render_environment_notice_script')) {
    /**
     * Render JavaScript to protect and re-inject environment notice
     * 
     * @return string JavaScript code
     */
    function lgl_render_environment_notice_script(): string {
        return '<script type="text/javascript">
        (function() {
            // Store the notice HTML for re-injection if needed
            var noticeHTML = document.getElementById("lgl-environment-notice");
            if (!noticeHTML) return;
            
            var originalHTML = noticeHTML.outerHTML;
            
            // Function to ensure notice is visible and protected
            function ensureEnvironmentNoticeVisible() {
                var notice = document.getElementById("lgl-environment-notice");
                if (!notice) {
                    // Notice was removed - re-inject it
                    var wrap = document.querySelector(".lgl-admin-page") || document.querySelector(".wrap");
                    if (wrap) {
                        var tempDiv = document.createElement("div");
                        tempDiv.innerHTML = originalHTML;
                        var h1 = wrap.querySelector("h1");
                        if (h1 && h1.nextSibling) {
                            wrap.insertBefore(tempDiv.firstChild, h1.nextSibling);
                            console.log("LGL Environment notice re-injected");
                        } else if (h1) {
                            // Insert after h1 if no nextSibling
                            h1.parentNode.insertBefore(tempDiv.firstChild, h1.nextSibling);
                        }
                    }
                } else {
                    // Notice exists - ensure it\'s visible and remove dismiss button
                    var dismissBtn = notice.querySelector(".notice-dismiss");
                    if (dismissBtn) {
                        dismissBtn.remove();
                    }
                    notice.style.display = "block";
                    notice.style.visibility = "visible";
                    notice.style.opacity = "1";
                }
            }
            
            // Initial setup
            ensureEnvironmentNoticeVisible();
            console.log("LGL Environment notice rendered and protected from dismissal");
            
            // Monitor for removal attempts using MutationObserver
            if (window.MutationObserver) {
                var observer = new MutationObserver(function(mutations) {
                    ensureEnvironmentNoticeVisible();
                });
                
                // Watch the wrap element for changes
                var wrapElement = document.querySelector(".lgl-admin-page") || document.querySelector(".wrap");
                if (wrapElement) {
                    observer.observe(wrapElement, {
                        childList: true,
                        subtree: true
                    });
                }
            }
            
            // Also check periodically as backup
            setInterval(ensureEnvironmentNoticeVisible, 1000);
        })();
        </script>';
    }
}

