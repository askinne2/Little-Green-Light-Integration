<?php
/**
 * Testing Tools Admin Page
 * 
 * Wrapper page for the membership testing utility shortcode.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

/**
 * Testing Tools Page Class
 */
class TestingToolsPage {
    /**
     * Render the page
     */
    public function render(): void {
        // Simply output the shortcode
        echo do_shortcode('[lgl_test_renewals]');
    }
}

