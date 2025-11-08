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
        
        // Bind connection test button if present
        $('#lgl-test-connection-btn').on('click', LGL.ConnectionTest.testConnection);
    });
    
})(jQuery);
