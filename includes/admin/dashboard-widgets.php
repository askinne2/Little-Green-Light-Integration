<?php
/**
 * Admin Dashboard Widgets
 * 
 * Provides admin dashboard widgets for nonprofit order summaries and events newsletter generation.
 * Moved from theme to plugin for proper separation of concerns.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Dashboard Widgets Manager Class
 * 
 * Handles all dashboard widget functionality with proper caching and error handling
 */
class LGL_Dashboard_Widgets {
    
    /**
     * Cache TTL for expensive operations (1 hour)
     */
    const CACHE_TTL = 3600;
    
    /**
     * Initialize dashboard widgets
     */
    public static function init() {
        // Only initialize if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }
        
        add_action('wp_dashboard_setup', [self::class, 'register_widgets']);
        add_action('admin_menu', [self::class, 'add_admin_pages']);
    }
    
    /**
     * Register dashboard widgets
     */
    public static function register_widgets() {
        wp_add_dashboard_widget(
            'lgl_nonprofit_dashboard_widget',
            'Nonprofit Order Summary',
            [self::class, 'render_nonprofit_widget']
        );
        
        wp_add_dashboard_widget(
            'lgl_events_newsletter_widget',
            'Events Newsletter for Constant Contact',
            [self::class, 'render_events_newsletter_widget']
        );
    }
    
    /**
     * Add admin pages
     */
    public static function add_admin_pages() {
        add_submenu_page(
            null, // Hidden from menu
            'Events Newsletter Preview',
            'Events Newsletter Preview',
            'manage_options',
            'lgl-events-newsletter-preview',
            [self::class, 'render_events_newsletter_page']
        );
    }
    
    /**
     * Render nonprofit order summary widget
     */
    public static function render_nonprofit_widget() {
        if (!class_exists('WooCommerce')) {
            echo '<div class="error"><p>WooCommerce is required for this widget.</p></div>';
            return;
        }

        try {
            self::handle_widget_form_submission();
            self::render_widget_styles();
            self::render_date_filter_form();
            self::render_order_summary_table();
            self::render_email_form();
        } catch (Exception $e) {
            error_log('LGL Dashboard Widget Error: ' . $e->getMessage());
            echo '<div class="error"><p>Error loading widget. Please try again later.</p></div>';
        }
    }
    
    /**
     * Handle form submissions for the widget
     */
    private static function handle_widget_form_submission() {
        // Handle email sending with proper nonce verification
        if (isset($_POST['send_summary_email']) && wp_verify_nonce($_POST['_wpnonce'], 'lgl_send_summary_email')) {
            $start_date = new DateTime(sanitize_text_field($_POST['email_start_date']) . ' 00:00:00');
            $end_date = new DateTime(sanitize_text_field($_POST['email_end_date']) . ' 23:59:59');
            $recipient = sanitize_email($_POST['email']);
            
            if ($recipient && is_email($recipient)) {
                $orders = self::get_filtered_orders($start_date, $end_date);
                if (!empty($orders)) {
                    self::send_order_summary_email($start_date, $end_date, $recipient, $orders);
                    echo '<div class="updated"><p>Email sent successfully!</p></div>';
                } else {
                    echo '<div class="error"><p>No orders found for the selected date range.</p></div>';
                }
            } else {
                echo '<div class="error"><p>Please provide a valid email address.</p></div>';
            }
        }
    }
    
    /**
     * Get filtered orders with caching
     */
    private static function get_filtered_orders($start_date, $end_date) {
        // Check if WooCommerce is available
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        
        $cache_key = 'lgl_orders_' . $start_date->format('Ymd') . '_' . $end_date->format('Ymd');
        
        $orders = get_transient($cache_key);
        if (false === $orders) {
            $orders = wc_get_orders([
                'status' => ['completed', 'processing', 'on-hold'],
                'date_created' => $start_date->format('Y-m-d') . '...' . $end_date->format('Y-m-d'),
                'limit' => -1,
            ]);
            
            // Cache for 1 hour
            set_transient($cache_key, $orders, self::CACHE_TTL);
        }
        
        return $orders;
    }
    
    /**
     * Send order summary email
     */
    private static function send_order_summary_email($start_date, $end_date, $recipient, $orders) {
        $subject = sprintf(
            'Order Summary: %s to %s',
            $start_date->format('M j, Y'),
            $end_date->format('M j, Y')
        );
        
        $message = self::build_email_content($orders, $start_date, $end_date);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>'
        ];
        
        wp_mail($recipient, $subject, $message, $headers);
    }
    
    /**
     * Build email content
     */
    private static function build_email_content($orders, $start_date, $end_date) {
        ob_start();
        ?>
        <html>
        <body style="font-family: Arial, sans-serif; color: #333;">
            <h2>Order Summary: <?php echo $start_date->format('M j, Y'); ?> to <?php echo $end_date->format('M j, Y'); ?></h2>
            <table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%;">
                <thead>
                    <tr style="background-color: #f2f2f2;">
                        <th>Order ID</th>
                        <th>Date</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Items</th>
                        <th>LGL ID</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <?php $order_data = self::format_order_data($order); ?>
                        <?php if ($order_data): ?>
                            <tr>
                                <td><?php echo esc_html($order_data['order_id']); ?></td>
                                <td><?php echo esc_html($order_data['date']); ?></td>
                                <td><?php echo esc_html($order_data['customer_name']); ?></td>
                                <td><?php echo self::format_price($order_data['total']); ?></td>
                                <td><?php echo esc_html($order_data['item_count']); ?></td>
                                <td><?php echo esc_html($order_data['lgl_id']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 20px; font-size: 12px; color: #666;">
                Generated automatically by <?php echo get_bloginfo('name'); ?>
            </p>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Format order data for display
     */
    private static function format_order_data($order) {
        if (!$order) return null;
        
        $user_id = $order->get_user_id();
        $lgl_id = $user_id ? get_user_meta($user_id, 'lgl_id', true) : '';
        
        return [
            'order_id' => $order->get_id(),
            'date' => $order->get_date_created()->format('M j, Y'),
            'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'total' => $order->get_total(),
            'item_count' => $order->get_item_count(),
            'lgl_id' => $lgl_id ?: 'N/A',
            'membership_type' => get_post_meta($order->get_id(), '_membership_type', true) ?: 'N/A',
            'subscription_status' => 'N/A', // TODO: Implement if needed
            'membership_start_date' => 'N/A', // TODO: Implement if needed
            'renewal_date' => 'N/A', // TODO: Implement if needed
            'subscription_id' => 'N/A', // TODO: Implement if needed
        ];
    }
    
    /**
     * Format price safely with WooCommerce fallback
     */
    private static function format_price($amount) {
        if (function_exists('wc_price')) {
            return wc_price($amount);
        }
        return '$' . number_format($amount, 2);
    }
    
    /**
     * Render widget styles
     */
    private static function render_widget_styles() {
        echo '<style>
            .lgl-widget .submit { text-align: right; }
            .lgl-order-table { font-size: 12px; overflow-x: auto; width: 100%; }
            .lgl-order-table th, .lgl-order-table td { padding: 4px; white-space: nowrap; }
            .lgl-order-table th { background-color: #f2f2f2; border-bottom: 2px solid #000; }
            .lgl-order-table td { border-bottom: 1px solid #ddd; }
            .lgl-form-row { display: flex; gap: 10px; margin: 10px 0; flex-wrap: wrap; }
            .lgl-form-field { flex: 1; min-width: 200px; }
        </style>';
    }
    
    /**
     * Render date filter form
     */
    private static function render_date_filter_form() {
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : 
                     (new DateTime('yesterday'))->format('Y-m-d');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : 
                   (new DateTime('yesterday'))->format('Y-m-d');
        
        echo '<form method="POST" class="lgl-widget">';
        wp_nonce_field('lgl_filter_orders', '_wpnonce');
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="start_date"><strong>Start Date:</strong></label>';
        echo '<input type="date" id="start_date" name="start_date" value="' . esc_attr($start_date) . '">';
        echo '</div>';
        echo '<div class="lgl-form-field">';
        echo '<label for="end_date"><strong>End Date:</strong></label>';
        echo '<input type="date" id="end_date" name="end_date" value="' . esc_attr($end_date) . '">';
        echo '</div>';
        echo '<div class="lgl-form-field" style="align-self: flex-end;">';
        submit_button('Filter Orders', 'secondary', 'filter_orders', false);
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    /**
     * Render order summary table
     */
    private static function render_order_summary_table() {
        $start_date = isset($_POST['start_date']) ? new DateTime(sanitize_text_field($_POST['start_date']) . ' 00:00:00') : new DateTime('yesterday 00:00:00');
        $end_date = isset($_POST['end_date']) ? new DateTime(sanitize_text_field($_POST['end_date']) . ' 23:59:59') : new DateTime('yesterday 23:59:59');
        
        $orders = self::get_filtered_orders($start_date, $end_date);
        
        echo '<h4 style="margin-top: 20px;">Order Summary</h4>';
        echo '<div style="overflow-x: auto;">';
        echo '<table class="widefat fixed lgl-order-table">';
        echo '<thead><tr>';
        echo '<th>Order ID</th><th>Date</th><th>Customer</th><th>Total</th><th>Items</th><th>LGL ID</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($orders as $order) {
            $order_data = self::format_order_data($order);
            if (!$order_data) continue;
            
            echo '<tr>';
            echo '<td>' . esc_html($order_data['order_id']) . '</td>';
            echo '<td>' . esc_html($order_data['date']) . '</td>';
            echo '<td>' . esc_html($order_data['customer_name']) . '</td>';
            echo '<td>' . self::format_price($order_data['total']) . '</td>';
            echo '<td>' . esc_html($order_data['item_count']) . '</td>';
            echo '<td>' . esc_html($order_data['lgl_id']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
    /**
     * Render email form
     */
    private static function render_email_form() {
        echo '<h4 style="margin-top: 20px;">Send Summary Email</h4>';
        echo '<hr>';
        echo '<form method="POST" class="lgl-widget">';
        wp_nonce_field('lgl_send_summary_email', '_wpnonce');
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="email_start_date"><strong>Start Date:</strong></label>';
        echo '<input type="date" id="email_start_date" name="email_start_date" required>';
        echo '</div>';
        echo '<div class="lgl-form-field">';
        echo '<label for="email_end_date"><strong>End Date:</strong></label>';
        echo '<input type="date" id="email_end_date" name="email_end_date" required>';
        echo '</div>';
        echo '</div>';
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        echo '<label for="email"><strong>Recipient Email:</strong></label>';
        echo '<input type="email" id="email" name="email" value="' . esc_attr(get_option('admin_email')) . '" required style="width: 100%;">';
        echo '</div>';
        echo '</div>';
        echo '<div class="lgl-form-row">';
        echo '<div class="lgl-form-field">';
        submit_button('Send Summary Email', 'primary', 'send_summary_email', false);
        echo '</div>';
        echo '</div>';
        echo '</form>';
    }
    
    /**
     * Render events newsletter widget
     */
    public static function render_events_newsletter_widget() {
        $preview_url = add_query_arg([
            'page' => 'lgl-events-newsletter-preview',
            'nonce' => wp_create_nonce('lgl_events_preview_nonce')
        ], admin_url('admin.php'));
        
        echo '<style>
            .lgl-events-widget { font-family: Arial, sans-serif; }
            .lgl-events-instructions { 
                background-color: #e8f4f8; 
                padding: 15px; 
                border-left: 4px solid #00797A; 
                margin-bottom: 20px; 
                border-radius: 5px; 
            }
            .lgl-events-button {
                text-align: center;
                padding: 20px;
                background-color: #f9f9f9;
                border: 2px dashed #00797A;
                border-radius: 5px;
                margin: 15px 0;
            }
            .lgl-events-button a {
                display: inline-block;
                background-color: #00797A;
                color: white;
                padding: 15px 30px;
                text-decoration: none;
                border-radius: 5px;
                font-weight: bold;
                font-size: 16px;
            }
            .lgl-events-button a:hover {
                background-color: #005f61;
                color: white;
            }
        </style>';

        echo '<div class="lgl-events-widget">';
        echo '<div class="lgl-events-instructions">';
        echo '<h3 style="margin-top: 0;">📧 Events Newsletter for Constant Contact</h3>';
        echo '<p><strong>Generate beautifully formatted event content for your newsletters.</strong></p>';
        echo '<p>Click the button below to open the events preview page with copy-ready content.</p>';
        echo '<p><em>💡 <strong>Note:</strong> Content is automatically generated from your latest upcoming events.</em></p>';
        echo '</div>';
        echo '<div class="lgl-events-button">';
        echo '<a href="' . esc_url($preview_url) . '" target="_blank">📅 Generate Events Newsletter Content</a>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render events newsletter admin page
     */
    public static function render_events_newsletter_page() {
        // Security checks
        if (!current_user_can('manage_options')) {
            wp_die('Access denied: Administrator privileges required.');
        }
        
        if (isset($_GET['nonce']) && !wp_verify_nonce($_GET['nonce'], 'lgl_events_preview_nonce')) {
            wp_die('Security check failed.');
        }
        
        echo '<div class="wrap">';
        echo '<style>
            .lgl-events-admin { font-family: Arial, sans-serif; }
            .lgl-events-header { background-color: #23282d; color: white; padding: 20px; margin: 0 -20px 20px -12px; }
            .lgl-events-header h1 { margin: 0; font-size: 28px; color: white; }
            .lgl-events-header p { margin: 10px 0 0 0; opacity: 0.8; font-size: 16px; }
            .lgl-events-instructions { background-color: #fff; border-left: 4px solid #00797A; padding: 20px; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .lgl-events-content { background-color: #fff; padding: 25px; border: 2px dashed #00797A; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
            .lgl-copy-note { text-align: center; font-weight: bold; color: #00797A; margin-bottom: 20px; font-size: 16px; }
            .kbd { background: #f1f1f1; border: 1px solid #ccc; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
        </style>';
        
        echo '<div class="lgl-events-admin">';
        echo '<div class="lgl-events-header">';
        echo '<h1>📧 Events Newsletter Generator</h1>';
        echo '<p>Create formatted content for Constant Contact newsletters</p>';
        echo '</div>';
        
        echo '<div class="lgl-events-instructions">';
        echo '<h2>📋 Instructions:</h2>';
        echo '<ol style="font-size: 15px; line-height: 1.6;">';
        echo '<li><strong>Select All:</strong> Click in the dashed box below, then press <span class="kbd">Ctrl+A</span> (PC) or <span class="kbd">Cmd+A</span> (Mac)</li>';
        echo '<li><strong>Copy:</strong> Press <span class="kbd">Ctrl+C</span> (PC) or <span class="kbd">Cmd+C</span> (Mac)</li>';
        echo '<li><strong>Paste:</strong> Go to Constant Contact and paste - formatting will be preserved!</li>';
        echo '</ol>';
        echo '</div>';
        
        echo '<div class="lgl-events-content">';
        echo '<div class="lgl-copy-note">👇 SELECT AND COPY ALL CONTENT BELOW 👇</div>';
        
        try {
            self::render_events_content();
        } catch (Exception $e) {
            echo '<div class="notice notice-error"><p>Error loading events. Please try again later.</p></div>';
            error_log('LGL Events Newsletter Error: ' . $e->getMessage());
        }
        
        echo '</div></div></div>';
    }
    
    /**
     * Render events content with caching
     */
    private static function render_events_content() {
        $cache_key = 'lgl_events_newsletter_content';
        $content = get_transient($cache_key);
        
        if (false === $content) {
            $content = self::generate_events_content();
            // Cache for 30 minutes
            set_transient($cache_key, $content, 1800);
        }
        
        echo $content;
    }
    
    /**
     * Generate events content
     */
    private static function generate_events_content() {
        $events_query = new WP_Query([
            'post_type' => 'ui-events',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'meta_query' => [
                [
                    'key' => 'ui_events_start_datetime',
                    'value' => current_time('timestamp'),
                    'compare' => '>=',
                    'type' => 'NUMERIC'
                ]
            ],
            'meta_key' => 'ui_events_start_datetime',
            'orderby' => 'meta_value_num',
            'order' => 'ASC'
        ]);
        
        if (!$events_query->have_posts()) {
            return '<div class="notice notice-warning"><p>No upcoming events found.</p></div>';
        }
        
        ob_start();
        echo '<div style="font-family: Arial, sans-serif; color: #333333; line-height: 1.5; max-width: 600px;">' . "\n";
        
        $count = 1;
        while ($events_query->have_posts()) : $events_query->the_post();
            $post_id = get_the_ID();
            $title = get_the_title();
            $link = get_permalink();
            
            // Get new datetime fields
            $start_timestamp = get_post_meta($post_id, 'ui_events_start_datetime', true);
            $location_name = get_post_meta($post_id, 'ui_events_location_name', true);
            $location_address = get_post_meta($post_id, 'ui_events_location_address', true);
            $price = get_post_meta($post_id, 'ui_events_price', true);
            
            $description = get_the_excerpt() ?: wp_trim_words(strip_tags(get_the_content()), 30);
            $description = html_entity_decode($description, ENT_QUOTES, 'UTF-8');
            
            // Format date and time
            $date_formatted = $start_timestamp ? date('l, F j, Y', $start_timestamp) : 'Date TBA';
            $time_formatted = $start_timestamp ? date('g:i A', $start_timestamp) : '';
            
            // Format price
            $price_display = ($price == 0) ? 'FREE' : '$' . number_format($price, 2);
            
            echo '<table width="100%" cellpadding="10" cellspacing="0" border="0" style="background-color: #f9f9f9; margin-bottom: 15px; border-left: 4px solid #00797A;">' . "\n";
            echo '<tr><td>' . "\n";
            echo '<h3 style="color: #00797A; margin: 0 0 10px 0; font-size: 18px;">' . $count . '. ' . esc_html($title) . '</h3>' . "\n";
            echo '<p style="margin: 3px 0; font-size: 14px;"><strong>📅 Date:</strong> ' . esc_html($date_formatted) . '</p>' . "\n";
            
            if ($time_formatted) {
                echo '<p style="margin: 3px 0; font-size: 14px;"><strong>🕐 Time:</strong> ' . esc_html($time_formatted) . '</p>' . "\n";
            }
            
            if ($location_name) {
                echo '<p style="margin: 3px 0; font-size: 14px;"><strong>📍 Location:</strong> ' . esc_html($location_name) . '</p>' . "\n";
                if ($location_address) {
                    echo '<p style="margin: 3px 0 8px 20px; font-size: 12px; color: #666;">📍 ' . esc_html($location_address) . '</p>' . "\n";
                }
            }
            
            $price_color = ($price == 0) ? '#28a745' : '#007bff';
            echo '<p style="margin: 3px 0; font-size: 14px;"><strong>💰 Cost:</strong> <span style="color: ' . $price_color . '; font-weight: bold;">' . esc_html($price_display) . '</span></p>' . "\n";
            
            if ($description && strlen($description) > 10) {
                $short_description = strlen($description) > 150 ? substr($description, 0, 150) . '...' : $description;
                echo '<p style="margin: 8px 0; font-size: 13px; color: #555; font-style: italic;">' . esc_html($short_description) . '</p>' . "\n";
            }
            
            echo '<table cellpadding="0" cellspacing="0" border="0" style="margin-top: 10px;"><tr><td style="padding: 8px 16px; border-radius: 3px;"><a href="' . esc_url($link) . '" style="color: #00797A; text-decoration: none; font-weight: bold; font-size: 14px;">📖 Learn More</a></td></tr></table>' . "\n";
            echo '</td></tr></table>' . "\n";
            echo '<p>&nbsp;</p>' . "\n\n";
            
            $count++;
            if ($count > 10) break;
        endwhile;
        
        wp_reset_postdata();
        
        // Footer
        echo '<table width="100%" cellpadding="15" cellspacing="0" border="0" style="background-color: #00797A; margin-top: 20px;"><tr><td align="center">' . "\n";
        echo '<p style="margin: 0 0 10px 0; font-size: 16px; font-weight: bold; color: #ffffff;">🌐 View All Events</p>' . "\n";
        echo '<p style="margin: 0 0 15px 0; font-size: 14px; color: #ffffff;">For the complete list of events:</p>' . "\n";
        echo '<table cellpadding="0" cellspacing="0" border="0"><tr><td style="background-color: #005f61; padding: 10px 20px; border-radius: 3px;"><a href="' . home_url('/events/') . '" style="color: #ffffff; text-decoration: none; font-weight: bold; font-size: 14px;">🗓️ Visit Events Calendar</a></td></tr></table>' . "\n";
        echo '</td></tr></table>' . "\n";
        echo '</div>' . "\n";
        
        return ob_get_clean();
    }
}

// Initialize the dashboard widgets only if modern version doesn't exist
// The modern version (DashboardWidgets) is initialized via ServiceContainer and HookManager
if (!class_exists('\UpstateInternational\LGL\Admin\DashboardWidgets')) {
LGL_Dashboard_Widgets::init();
}
