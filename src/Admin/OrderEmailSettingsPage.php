<?php
/**
 * Order Email Settings Page
 * 
 * Admin interface for configuring order confirmation email templates.
 * Provides email editor, test functionality, and variable reference guide.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Admin;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Email\OrderEmailCustomizer;

/**
 * OrderEmailSettingsPage Class
 * 
 * Manages order email template settings admin interface
 */
class OrderEmailSettingsPage {
    
    /**
     * Settings manager
     * 
     * @var SettingsManager
     */
    private SettingsManager $settingsManager;
    
    /**
     * Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Order email customizer
     * 
     * @var OrderEmailCustomizer
     */
    private OrderEmailCustomizer $emailCustomizer;
    
    /**
     * Constructor
     * 
     * @param SettingsManager $settingsManager Settings manager
     * @param Helper $helper Helper service
     * @param OrderEmailCustomizer $emailCustomizer Order email customizer
     */
    public function __construct(
        SettingsManager $settingsManager,
        Helper $helper,
        OrderEmailCustomizer $emailCustomizer
    ) {
        $this->settingsManager = $settingsManager;
        $this->helper = $helper;
        $this->emailCustomizer = $emailCustomizer;
        
        // Handle form submissions
        add_action('admin_post_lgl_save_order_email_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_lgl_test_order_email', [$this, 'handleTestEmail']);
    }
    
    /**
     * Render the settings page
     * 
     * @return void
     */
    public function render(): void {
        $settings = $this->settingsManager->getAll();
        
        // Check for success/error messages
        $updated = isset($_GET['updated']) ? $_GET['updated'] : null;
        $test_sent = isset($_GET['test_sent']) ? $_GET['test_sent'] : null;
        
        ?>
        <div class="wrap">
            <h1>Order Confirmation Email Templates</h1>
            
            <?php if ($updated === 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Settings saved successfully!</p>
                </div>
            <?php elseif ($updated === 'false'): ?>
                <div class="notice notice-error is-dismissible">
                    <p>Failed to save settings. Please try again.</p>
                </div>
            <?php endif; ?>
            
            <?php if ($test_sent === 'true'): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Test email sent successfully to <?php echo esc_html(urldecode($_GET['test_email'] ?? '')); ?>!</p>
                </div>
            <?php elseif ($test_sent === 'false'): ?>
                <div class="notice notice-error is-dismissible">
                    <p>Failed to send test email. Please check your email configuration.</p>
                </div>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><strong>💡 How it works:</strong> Customize email templates for different product categories. 
                Templates stored in the database will be used instead of the default HTML files. 
                If a database template is empty, the system will fall back to the original HTML file.</p>
            </div>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lgl_order_email_settings'); ?>
                <input type="hidden" name="action" value="lgl_save_order_email_settings">
                
                <?php $this->renderEmailTemplates($settings); ?>
                
                <?php submit_button('Save Email Templates'); ?>
            </form>
            
            <?php $this->renderTestEmailSection(); ?>
        </div>
        <?php
    }
    
    /**
     * Render email templates section
     * 
     * @param array $settings Current settings
     * @return void
     */
    private function renderEmailTemplates(array $settings): void {
        $categories = $this->getProductCategories();
        
        ?>
        <h2>Email Templates</h2>
        <p class="description">
            Configure email subject lines and content for each product category. 
            Templates will be pre-filled with default content from existing HTML files. 
            Leave a template empty to use the default HTML file.
        </p>
        
        <style>
            .order-email-template-editor {
                background: #f9f9f9;
                padding: 20px;
                margin-bottom: 30px;
                border-left: 4px solid #2271b1;
                border-radius: 4px;
            }
            .order-email-template-editor h3 {
                margin-top: 0;
                color: #2271b1;
            }
            .order-email-template-editor label {
                display: block;
                margin-top: 15px;
                font-weight: 600;
            }
            .order-email-template-editor input[type="text"] {
                width: 100%;
                margin-top: 5px;
            }
            .order-email-template-editor .variables-list {
                background: #fff;
                padding: 10px;
                margin-top: 10px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 12px;
            }
            .order-email-template-editor .variables-list code {
                background: #f0f0f0;
                padding: 2px 6px;
                border-radius: 3px;
                margin-right: 5px;
            }
        </style>
        
        <?php foreach ($categories as $key => $category): ?>
            <div class="order-email-template-editor">
                <h3><?php echo esc_html($category['label']); ?></h3>
                
                <div class="variables-list">
                    <strong>Available variables:</strong>
                    <?php foreach ($category['variables'] as $variable): ?>
                        <code><?php echo esc_html($variable); ?></code>
                    <?php endforeach; ?>
                </div>
                
                <label for="order_email_subject_<?php echo esc_attr($key); ?>">
                    Subject Line:
                </label>
                <input type="text" 
                       name="order_email_subject_<?php echo esc_attr($key); ?>" 
                       id="order_email_subject_<?php echo esc_attr($key); ?>"
                       value="<?php echo esc_attr($settings["order_email_template_{$key}_subject"] ?? ''); ?>"
                       class="large-text"
                       placeholder="Leave empty to use WooCommerce default">
                
                <label for="order_email_content_<?php echo esc_attr($key); ?>">
                    Email Content (HTML):
                </label>
                <?php 
                // Get default content from file if database value is empty
                $content = $settings["order_email_template_{$key}_content"] ?? '';
                if (empty($content)) {
                    $template_file = $this->getTemplateFileForType($key);
                    if ($template_file) {
                        $file_path = LGL_PLUGIN_DIR . 'form-emails/' . $template_file;
                        if (file_exists($file_path)) {
                            $content = file_get_contents($file_path);
                        }
                    }
                }
                
                wp_editor(
                    $content,
                    "order_email_content_{$key}",
                    [
                        'textarea_rows' => 15,
                        'media_buttons' => false,
                        'teeny' => false,
                        'textarea_name' => "order_email_content_{$key}"
                    ]
                );
                ?>
            </div>
        <?php endforeach; ?>
        <?php
    }
    
    /**
     * Render test email section
     * 
     * @return void
     */
    private function renderTestEmailSection(): void {
        ?>
        <hr style="margin-top: 40px;">
        <h2>Test Order Emails</h2>
        <div class="card">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('lgl_test_order_email'); ?>
                <input type="hidden" name="action" value="lgl_test_order_email">
                
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="test_template_type">Template Type</label>
                            </th>
                            <td>
                                <select name="test_template_type" id="test_template_type" required>
                                    <option value="">-- Select Template --</option>
                                    <option value="membership_new">New Membership</option>
                                    <option value="membership_renewal">Membership Renewal</option>
                                    <option value="language_class">Language Class</option>
                                    <option value="event_with_lunch">Event (With Lunch)</option>
                                    <option value="event_no_lunch">Event (No Lunch)</option>
                                    <option value="general">General Orders (Catch-All)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="test_email">Recipient Email</label>
                            </th>
                            <td>
                                <input type="email" 
                                       name="test_email" 
                                       id="test_email"
                                       placeholder="your-email@example.com" 
                                       class="regular-text"
                                       value="<?php echo esc_attr(get_option('admin_email')); ?>"
                                       required>
                            </td>
                        </tr>
                    </tbody>
                </table>
                
                <?php submit_button('Send Test Email', 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Handle save settings form submission
     * 
     * @return void
     */
    public function handleSaveSettings(): void {
        check_admin_referer('lgl_order_email_settings');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $settings = [];
        $categories = $this->getProductCategories();
        
        // Save subject and content for each template type
        foreach ($categories as $key => $category) {
            $settings["order_email_template_{$key}_subject"] = sanitize_text_field(
                $_POST["order_email_subject_{$key}"] ?? ''
            );
            $settings["order_email_template_{$key}_content"] = wp_kses_post(
                $_POST["order_email_content_{$key}"] ?? ''
            );
        }
        
        // Save settings
        $result = $this->settingsManager->update($settings);
        
        // Redirect with message
        $redirect = add_query_arg(
            ['page' => 'lgl-order-email-settings', 'updated' => $result ? 'true' : 'false'],
            admin_url('admin.php')
        );
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Handle test email form submission
     * 
     * @return void
     */
    public function handleTestEmail(): void {
        check_admin_referer('lgl_test_order_email');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $test_email = sanitize_email($_POST['test_email'] ?? '');
        $template_type = sanitize_text_field($_POST['test_template_type'] ?? '');
        
        if (!is_email($test_email) || empty($template_type)) {
            $redirect = add_query_arg(
                [
                    'page' => 'lgl-order-email-settings',
                    'test_sent' => 'false'
                ],
                admin_url('admin.php')
            );
            wp_redirect($redirect);
            exit;
        }
        
        // Get template content
        $settings = $this->settingsManager->getAll();
        $subject = $settings["order_email_template_{$template_type}_subject"] ?? '';
        $content = $settings["order_email_template_{$template_type}_content"] ?? '';
        
        // If no database template, use file-based template
        if (empty($content)) {
            $template_file = $this->getTemplateFileForType($template_type);
            if ($template_file) {
                $file_path = LGL_PLUGIN_DIR . 'form-emails/' . $template_file;
                if (file_exists($file_path)) {
                    $content = file_get_contents($file_path);
                }
            }
        }
        
        // Replace variables with sample data
        $sample_data = $this->getSampleDataForType($template_type);
        $content = str_replace(array_keys($sample_data), array_values($sample_data), $content);
        if (!empty($subject)) {
            $subject = str_replace(array_keys($sample_data), array_values($sample_data), $subject);
        } else {
            $subject = '[TEST] Order Confirmation';
        }
        
        // Send test email
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $result = wp_mail($test_email, $subject, $content, $headers);
        
        // Redirect with message
        $redirect = add_query_arg(
            [
                'page' => 'lgl-order-email-settings',
                'test_sent' => $result ? 'true' : 'false',
                'test_email' => urlencode($test_email)
            ],
            admin_url('admin.php')
        );
        wp_redirect($redirect);
        exit;
    }
    
    /**
     * Get product categories configuration
     * 
     * @return array Product categories with labels and variables
     */
    private function getProductCategories(): array {
        return [
            'membership_new' => [
                'label' => 'New Membership',
                'variables' => ['{first_name}', '{last_name}', '{membership_level}', '{order_id}', '{order_date}', '{order_total}']
            ],
            'membership_renewal' => [
                'label' => 'Membership Renewal',
                'variables' => ['{first_name}', '{last_name}', '{membership_level}', '{order_id}', '{order_date}', '{order_total}', '{renewal_date}']
            ],
            'language_class' => [
                'label' => 'Language Class',
                'variables' => ['{first_name}', '{class_name}', '{order_id}', '{order_date}']
            ],
            'event_with_lunch' => [
                'label' => 'Event (With Lunch)',
                'variables' => ['{first_name}', '{event_name}', '{event_date}', '{event_time}', '{event_location}', '{speaker_name}', '{order_id}']
            ],
            'event_no_lunch' => [
                'label' => 'Event (No Lunch)',
                'variables' => ['{first_name}', '{event_name}', '{event_date}', '{event_time}', '{event_location}', '{speaker_name}', '{order_id}']
            ],
            'general' => [
                'label' => 'General Orders (Catch-All)',
                'variables' => ['{first_name}', '{last_name}', '{order_id}', '{order_date}', '{order_total}', '{product_name}']
            ]
        ];
    }
    
    /**
     * Get template file name for type
     * 
     * @param string $type Template type
     * @return string|null Template filename
     */
    private function getTemplateFileForType(string $type): ?string {
        $files = [
            'membership_new' => 'membership-confirmation.html',
            'membership_renewal' => 'membership-renewal.html',
            'language_class' => 'language-class-registration.html',
            'event_with_lunch' => 'event-with-lunch.html',
            'event_no_lunch' => 'event-no-lunch.html',
            'general' => 'general-order-confirmation.html'
        ];
        
        return $files[$type] ?? null;
    }
    
    /**
     * Get sample data for test email
     * 
     * @param string $type Template type
     * @return array Sample replacement data
     */
    private function getSampleDataForType(string $type): array {
        $base_data = [
            '{first_name}' => 'John',
            '{last_name}' => 'Doe',
            '{order_id}' => '12345',
            '{order_date}' => date('F j, Y'),
            '{order_total}' => '$75.00'
        ];
        
        switch ($type) {
            case 'membership_new':
            case 'membership_renewal':
                $base_data['{membership_level}'] = 'Individual Membership';
                if ($type === 'membership_renewal') {
                    $base_data['{renewal_date}'] = date('F j, Y', strtotime('+1 year'));
                }
                break;
                
            case 'language_class':
                $base_data['{class_name}'] = 'Spanish Level 1';
                break;
                
            case 'event_with_lunch':
            case 'event_no_lunch':
                $base_data['{event_name}'] = 'Sample Event';
                $base_data['{event_date}'] = date('F j, Y', strtotime('+2 weeks'));
                $base_data['{event_time}'] = '6:00 PM';
                $base_data['{event_location}'] = 'Upstate International Office, 123 Main St, Greenville, SC';
                $base_data['{speaker_name}'] = 'Dr. Jane Smith';
                break;
                
            case 'general':
                $base_data['{product_name}'] = 'Sample Product';
                break;
        }
        
        return $base_data;
    }
}

