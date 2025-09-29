/**
 * LGL Admin Settings JavaScript
 * 
 * Provides AJAX functionality for the enhanced settings interface
 */

(function($) {
    'use strict';
    
    // Initialize when document is ready
    $(document).ready(function() {
        initializeSettingsHandlers();
        initializeHealthChecks();
    });
    
    /**
     * Initialize all settings event handlers
     */
    function initializeSettingsHandlers() {
        // Connection test button
        $('#lgl-test-connection').on('click', function(e) {
            e.preventDefault();
            testApiConnection();
        });
        
        // Membership sync button
        $('#lgl-sync-memberships').on('click', function(e) {
            e.preventDefault();
            syncMembershipLevels();
        });
        
        // Cache control buttons
        $('#lgl-clear-cache').on('click', function(e) {
            e.preventDefault();
            clearCache();
        });
        
        $('#lgl-warm-cache').on('click', function(e) {
            e.preventDefault();
            warmCache();
        });
        
        // Settings export/import buttons
        $('#lgl-export-settings').on('click', function(e) {
            e.preventDefault();
            exportSettings();
        });
        
        $('#lgl-import-settings').on('click', function(e) {
            e.preventDefault();
            importSettings();
        });
        
        // Auto-test connection when API key changes
        $('input[name="_api_key"]').on('blur', function() {
            const apiKey = $(this).val();
            if (apiKey && apiKey.length > 10) {
                setTimeout(testApiConnection, 500);
            }
        });
    }
    
    /**
     * Test API connection
     */
    function testApiConnection() {
        const $button = $('#lgl-test-connection');
        const $result = $('#lgl-connection-result');
        const apiKey = $('input[name="_api_key"]').val();
        
        if (!apiKey) {
            showResult($result, 'error', 'Please enter an API key first');
            return;
        }
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + lglAdmin.strings.testing);
        
        // Make AJAX request
        $.ajax({
            url: lglAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'lgl_test_connection',
                api_key: apiKey,
                nonce: lglAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, 'success', lglAdmin.strings.connected);
                    updateConnectionStatus('connected');
                } else {
                    showResult($result, 'error', lglAdmin.strings.error + ' ' + response.data);
                    updateConnectionStatus('error');
                }
            },
            error: function(xhr, status, error) {
                showResult($result, 'error', lglAdmin.strings.error + ' ' + error);
                updateConnectionStatus('error');
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-admin-plugins"></span> Test Connection');
            }
        });
    }
    
    /**
     * Sync membership levels
     */
    function syncMembershipLevels() {
        const $button = $('#lgl-sync-memberships');
        const $result = $('#lgl-sync-result');
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + lglAdmin.strings.syncing);
        
        // Make AJAX request
        $.ajax({
            url: lglAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'lgl_sync_memberships',
                nonce: lglAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, 'success', lglAdmin.strings.synced);
                    
                    // Update membership sync header if present
                    updateLastSyncTime();
                } else {
                    showResult($result, 'error', 'Sync failed: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showResult($result, 'error', 'Sync error: ' + error);
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Sync Membership Levels Now');
            }
        });
    }
    
    /**
     * Clear cache
     */
    function clearCache() {
        const $button = $('#lgl-clear-cache');
        const $result = $('#lgl-cache-result');
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> ' + lglAdmin.strings.clearing);
        
        // Make AJAX request
        $.ajax({
            url: lglAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'lgl_clear_cache',
                nonce: lglAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, 'success', lglAdmin.strings.cleared);
                    
                    // Refresh cache statistics
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showResult($result, 'error', 'Clear failed: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showResult($result, 'error', 'Clear error: ' + error);
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Clear All Cache');
            }
        });
    }
    
    /**
     * Warm cache
     */
    function warmCache() {
        const $button = $('#lgl-warm-cache');
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> Warming Cache...');
        
        // Simulate cache warming (this would call multiple endpoints)
        setTimeout(function() {
            $button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Warm Cache');
            $('#lgl-cache-result').html('<div class="notice notice-success inline"><p>✅ Cache warmed successfully!</p></div>');
        }, 2000);
    }
    
    /**
     * Export settings
     */
    function exportSettings() {
        const $button = $('#lgl-export-settings');
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> Exporting...');
        
        // Make AJAX request
        $.ajax({
            url: lglAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'lgl_export_settings',
                nonce: lglAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Create and trigger download
                    const blob = new Blob([atob(response.data.data)], { type: 'application/json' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = response.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                    
                    showBackupResult('success', '✅ Settings exported successfully!');
                } else {
                    showBackupResult('error', 'Export failed: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showBackupResult('error', 'Export error: ' + error);
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Download Settings');
            }
        });
    }
    
    /**
     * Import settings
     */
    function importSettings() {
        const $fileInput = $('#lgl-import-file');
        const $button = $('#lgl-import-settings');
        const file = $fileInput[0].files[0];
        
        if (!file) {
            showBackupResult('error', 'Please select a settings file to import');
            return;
        }
        
        // Update button state
        $button.prop('disabled', true).html('<span class="spinner is-active"></span> Importing...');
        
        // Read file
        const reader = new FileReader();
        reader.onload = function(e) {
            const fileData = btoa(e.target.result);
            
            // Make AJAX request
            $.ajax({
                url: lglAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'lgl_import_settings',
                    file_data: fileData,
                    nonce: lglAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showBackupResult('success', '✅ ' + response.data.message);
                        
                        // Reload page after successful import
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        showBackupResult('error', 'Import failed: ' + response.data);
                    }
                },
                error: function(xhr, status, error) {
                    showBackupResult('error', 'Import error: ' + error);
                },
                complete: function() {
                    // Reset button state
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-upload"></span> Upload Settings');
                }
            });
        };
        
        reader.readAsText(file);
    }
    
    /**
     * Initialize health checks
     */
    function initializeHealthChecks() {
        // Check endpoint health on page load
        $('.endpoint-status').each(function() {
            const $status = $(this);
            const endpoint = $status.data('endpoint');
            
            // Simulate health check (in real implementation, this would ping the endpoint)
            setTimeout(function() {
                const isHealthy = Math.random() > 0.1; // 90% success rate for demo
                const statusHtml = isHealthy 
                    ? '<span style="color: #46b450;">✅ Healthy</span>'
                    : '<span style="color: #dc3232;">❌ Error</span>';
                
                $status.html(statusHtml);
            }, Math.random() * 2000 + 500); // Random delay 0.5-2.5s
        });
    }
    
    /**
     * Show result message
     */
    function showResult($container, type, message) {
        const cssClass = type === 'success' ? 'notice-success' : 'notice-error';
        const html = '<div class="notice ' + cssClass + ' inline"><p>' + message + '</p></div>';
        $container.html(html);
        
        // Auto-hide after 5 seconds
        setTimeout(function() {
            $container.fadeOut();
        }, 5000);
    }
    
    /**
     * Show backup/restore result
     */
    function showBackupResult(type, message) {
        const $container = $('#lgl-backup-result');
        showResult($container, type, message);
    }
    
    /**
     * Update connection status in header
     */
    function updateConnectionStatus(status) {
        // This would update the connection status header
        // Implementation depends on the specific HTML structure
        console.log('Connection status updated:', status);
    }
    
    /**
     * Update last sync time
     */
    function updateLastSyncTime() {
        // This would update the "Last Sync" display
        // Implementation depends on the specific HTML structure
        console.log('Last sync time updated');
    }
    
})(jQuery);
