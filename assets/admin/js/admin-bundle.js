/**
 * LGL Admin Bundle - MODERNIZED
 * Consolidated JavaScript for all admin pages
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

(function($) {
    'use strict';
    
    // LGL Admin namespace
    window.LGL = window.LGL || {};
    
    /**
     * Connection Test Module
     */
    LGL.ConnectionTest = {
        
        /**
         * Test API connection
         */
        testConnection: function(event) {
            const button = event.target;
            const resultDiv = document.getElementById('lgl-connection-result');
            const originalText = button.textContent;
            
            // Update button state
            button.textContent = '🔄 Testing...';
            button.disabled = true;
            
            // Get nonce from button data attribute
            const nonce = button.dataset.nonce || '';
            
            // Make AJAX request
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'lgl_test_connection',
                    nonce: nonce
                })
            })
            .then(response => response.json())
            .then(data => {
                // Show result
                if (resultDiv) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'lgl-connection-result ' + (data.success ? 'success' : 'error');
                    resultDiv.textContent = data.data?.message || (data.success ? '✅ Connection successful!' : '❌ Connection failed');
                }
                
                // Reset button after delay
                setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                    if (resultDiv) {
                        resultDiv.style.display = 'none';
                    }
                }, 3000);
            })
            .catch(error => {
                console.error('Connection test error:', error);
                
                if (resultDiv) {
                    resultDiv.style.display = 'block';
                    resultDiv.className = 'lgl-connection-result error';
                    resultDiv.textContent = '❌ Error: ' + error.message;
                }
                
                // Reset button
                setTimeout(() => {
                    button.textContent = originalText;
                    button.disabled = false;
                    if (resultDiv) {
                        resultDiv.style.display = 'none';
                    }
                }, 3000);
            });
        }
    };
    
    /**
     * Testing Suite Module
     */
    LGL.TestingSuite = {
        
        /**
         * Run a test
         */
        runTest: function(event) {
            const button = event.target;
            const originalText = button.textContent;
            const testType = button.dataset.testType;
            const nonce = button.dataset.nonce;
            const resultId = 'lgl-result-' + testType;
            const resultDiv = document.getElementById(resultId);
            
            // Update button state
            button.textContent = '🔄 Running...';
            button.disabled = true;
            
            // Clear previous results
            if (resultDiv) {
                resultDiv.innerHTML = '<p style="color:#2271b1;">⏳ Test in progress...</p>';
            }
            
            // Build request data
            const requestData = {
                action: 'lgl_run_test',
                nonce: nonce,
                test_type: testType
            };
            
            // Add any data attributes from the button
            for (const key in button.dataset) {
                if (key !== 'testType' && key !== 'nonce') {
                    requestData[key] = button.dataset[key];
                }
            }
            
            // Make AJAX request
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(requestData)
            })
            .then(response => response.text())
            .then(html => {
                // Display result HTML
                if (resultDiv) {
                    resultDiv.innerHTML = html;
                }
                
                // Reset button
                button.textContent = originalText;
                button.disabled = false;
            })
            .catch(error => {
                console.error('Test error:', error);
                
                if (resultDiv) {
                    resultDiv.innerHTML = '<div class="notice notice-error"><p>❌ <strong>Test Failed:</strong> ' + error.message + '</p></div>';
                }
                
                // Reset button
                button.textContent = originalText;
                button.disabled = false;
            });
        },
        
        /**
         * Initialize all test buttons
         */
        init: function() {
            $('.lgl-test-button').on('click', this.runTest);
        }
    };
    
    /**
     * Settings Page Module
     */
    LGL.SettingsPage = {
        
        /**
         * Initialize settings page
         */
        init: function() {
            this.initTabs();
            this.initFormValidation();
        },
        
        /**
         * Initialize tab navigation
         */
        initTabs: function() {
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                
                // Update active tab
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                
                // Show corresponding content
                const targetTab = $(this).data('tab');
                $('.lgl-tab-content').hide();
                $('#' + targetTab).show();
            });
        },
        
        /**
         * Initialize form validation
         */
        initFormValidation: function() {
            $('form[action*="admin-post.php"]').on('submit', function(e) {
                // Basic validation - can be extended
                const requiredFields = $(this).find('[required]');
                let isValid = true;
                
                requiredFields.each(function() {
                    if (!$(this).val()) {
                        isValid = false;
                        $(this).addClass('error');
                    } else {
                        $(this).removeClass('error');
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields.');
                    return false;
                }
            });
        }
    };
    
    /**
     * Utilities
     */
    LGL.Utils = {
        
        /**
         * Show loading state on element
         */
        showLoading: function(element) {
            $(element).addClass('lgl-loading');
        },
        
        /**
         * Hide loading state
         */
        hideLoading: function(element) {
            $(element).removeClass('lgl-loading');
        },
        
        /**
         * Display notification
         */
        showNotice: function(message, type = 'info') {
            const noticeClass = 'lgl-notice-' + type;
            const notice = $('<div>')
                .addClass('lgl-notice ' + noticeClass)
                .text(message)
                .prependTo('.lgl-admin-page');
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    };
    
    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Initialize settings page if present
        if ($('.lgl-settings-page').length) {
            LGL.SettingsPage.init();
        }
        
        // Initialize testing suite if present
        if ($('.lgl-testing-grid').length) {
            LGL.TestingSuite.init();
        }
        
        // Bind connection test button if present
        $('#lgl-test-connection-btn').on('click', LGL.ConnectionTest.testConnection);
    });
    
})(jQuery);
