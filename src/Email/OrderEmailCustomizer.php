<?php
/**
 * Order Email Customizer
 * 
 * Customizes WooCommerce email content based on product categories.
 * Handles dynamic email templates for memberships, classes, and events.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\Email;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Admin\SettingsManager;

/**
 * OrderEmailCustomizer Class
 * 
 * Customizes WooCommerce order emails with product-specific templates
 */
class OrderEmailCustomizer {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Settings manager (optional, for database templates)
     * 
     * @var SettingsManager|null
     */
    private ?SettingsManager $settingsManager = null;
    
    /**
     * Email template base path
     * 
     * @var string
     */
    private string $templateBasePath;
    
    /**
     * Special product IDs with custom templates
     * 
     * @var array<int, string>
     */
    private array $specialProductTemplates = [
        73955 => 'global-fluency-workshop.html' // Global Fluency Workshop
    ];
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param string $templateBasePath Base path for email templates
     * @param SettingsManager|null $settingsManager Settings manager (optional)
     */
    public function __construct(Helper $helper, string $templateBasePath = '', ?SettingsManager $settingsManager = null) {
        $this->helper = $helper;
        $this->templateBasePath = $templateBasePath ?: LGL_PLUGIN_DIR . 'form-emails/';
        $this->settingsManager = $settingsManager;
    }
    
    /**
     * Customize email content based on product category
     * 
     * @param \WC_Order $order WooCommerce order
     * @param bool $sent_to_admin Whether email is sent to admin
     * @param bool $plain_text Whether email is plain text
     * @param \WC_Email $email Email object
     * @return void
     */
    public function customizeEmailContent(\WC_Order $order, bool $sent_to_admin, bool $plain_text, \WC_Email $email): void {
        $this->helper->debug('OrderEmailCustomizer: Running email customizer');
        
        // Only customize customer emails (not admin emails)
        if ($sent_to_admin || !$order->get_items()) {
            return;
        }
        
        $template_info = null;
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            $product_name = $item->get_name();
            
            $template_info = $this->getTemplateForProduct($product_id, $product_name, $order);
            
            if ($template_info) {
                $this->renderEmailTemplate($template_info, $order, $product_id);
                break; // Only process the first matching product
            }
        }
        
        // If no template was found and we have a general template, use it
        // This handles the case where getGeneralTemplate() returns null (no database template)
        // but we still want to check if a general template exists
        if (!$template_info && $this->settingsManager) {
            $settings = $this->settingsManager->getAll();
            $general_content = $settings['order_email_template_general_content'] ?? '';
            if (!empty($general_content)) {
                $template_info = [
                    'source' => 'database',
                    'type' => 'general',
                    'content' => $general_content,
                    'subject' => $settings['order_email_template_general_subject'] ?? '',
                    'dynamic_data' => false
                ];
                // Use first product ID from order
                $first_item = reset($order->get_items());
                $first_product_id = $first_item ? $first_item->get_product_id() : 0;
                $this->renderEmailTemplate($template_info, $order, $first_product_id);
            }
        }
    }
    
    /**
     * Get email template for product
     * 
     * @param int $product_id Product ID
     * @param string $product_name Product name
     * @param \WC_Order $order WooCommerce order
     * @return array|null Template information or null if no template found
     */
    private function getTemplateForProduct(int $product_id, string $product_name, \WC_Order $order): ?array {
        // Try database template first
        $db_template = $this->getTemplateFromDatabase($product_id, $product_name, $order);
        if ($db_template) {
            return $db_template;
        }
        
        // Fall back to file-based templates
        $template_info = null;
        
        // Check for membership products
        if (has_term('memberships', 'product_cat', $product_id)) {
            $template_info = $this->getMembershipTemplate($order);
        }
        // Check for language class products
        elseif (has_term('language-class', 'product_cat', $product_id)) {
            $template_info = [
                'file' => 'language-class-registration.html',
                'dynamic_data' => false,
                'source' => 'file'
            ];
        }
        // Check for event products
        elseif (has_term('events', 'product_cat', $product_id)) {
            $template_info = $this->getEventTemplate($product_id, $product_name);
        }
        // Check for general/catch-all template if no category matches
        else {
            $template_info = $this->getGeneralTemplate();
        }
        
        return $template_info;
    }
    
    /**
     * Get general/catch-all email template
     * 
     * @return array Template information
     */
    private function getGeneralTemplate(): array {
        // Check database first
        if ($this->settingsManager) {
            $settings = $this->settingsManager->getAll();
            $content = $settings['order_email_template_general_content'] ?? '';
            if (!empty($content)) {
                return [
                    'source' => 'database',
                    'type' => 'general',
                    'content' => $content,
                    'subject' => $settings['order_email_template_general_subject'] ?? '',
                    'dynamic_data' => false
                ];
            }
        }
        
        // Return null to use default WooCommerce email (no custom template)
        // This allows WooCommerce to handle orders that don't match any category
        return null;
    }
    
    /**
     * Get template from database
     * 
     * @param int $product_id Product ID
     * @param string $product_name Product name
     * @param \WC_Order $order WooCommerce order
     * @return array|null Template information or null if not found
     */
    private function getTemplateFromDatabase(int $product_id, string $product_name, \WC_Order $order): ?array {
        if (!$this->settingsManager) {
            return null;
        }
        
        $settings = $this->settingsManager->getAll();
        
        // Check membership
        if (has_term('memberships', 'product_cat', $product_id)) {
            $is_renewal = function_exists('wcs_order_contains_renewal') ? wcs_order_contains_renewal($order) : false;
            $key = $is_renewal ? 'membership_renewal' : 'membership_new';
            
            $content = $settings["order_email_template_{$key}_content"] ?? '';
            if (!empty($content)) {
                return [
                    'source' => 'database',
                    'type' => $key,
                    'content' => $content,
                    'subject' => $settings["order_email_template_{$key}_subject"] ?? '',
                    'dynamic_data' => false
                ];
            }
        }
        
        // Check language class
        elseif (has_term('language-class', 'product_cat', $product_id)) {
            $content = $settings['order_email_template_language_class_content'] ?? '';
            if (!empty($content)) {
                return [
                    'source' => 'database',
                    'type' => 'language_class',
                    'content' => $content,
                    'subject' => $settings['order_email_template_language_class_subject'] ?? '',
                    'dynamic_data' => false
                ];
            }
        }
        
        // Check events
        elseif (has_term('events', 'product_cat', $product_id)) {
            // Check for special product templates first
            if (isset($this->specialProductTemplates[$product_id])) {
                // Special products still use file-based templates
                return null;
            }
            
            $has_lunch = strpos(strtolower($product_name), 'free') !== false;
            $key = $has_lunch ? 'event_with_lunch' : 'event_no_lunch';
            
            $content = $settings["order_email_template_{$key}_content"] ?? '';
            if (!empty($content)) {
                return [
                    'source' => 'database',
                    'type' => $key,
                    'content' => $content,
                    'subject' => $settings["order_email_template_{$key}_subject"] ?? '',
                    'dynamic_data' => true
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Get membership email template
     * 
     * @param \WC_Order $order WooCommerce order
     * @return array Template information
     */
    private function getMembershipTemplate(\WC_Order $order): array {
        $is_renewal = function_exists('wcs_order_contains_renewal') ? wcs_order_contains_renewal($order) : false;
        
        return [
            'file' => $is_renewal ? 'membership-renewal.html' : 'membership-confirmation.html',
            'dynamic_data' => false,
            'source' => 'file'
        ];
    }
    
    /**
     * Get event email template
     * 
     * @param int $product_id Product ID
     * @param string $product_name Product name
     * @return array Template information
     */
    private function getEventTemplate(int $product_id, string $product_name): array {
        // Check for special product templates
        if (isset($this->specialProductTemplates[$product_id])) {
            return [
                'file' => $this->specialProductTemplates[$product_id],
                'dynamic_data' => true,
                'source' => 'file'
            ];
        }
        
        // Check if variation name contains "free" for lunch determination
        $template_file = strpos(strtolower($product_name), 'free') !== false 
            ? 'event-with-lunch.html' 
            : 'event-no-lunch.html';
        
        return [
            'file' => $template_file,
            'dynamic_data' => true,
            'source' => 'file'
        ];
    }
    
    /**
     * Render email template
     * 
     * @param array $template_info Template information
     * @param \WC_Order $order WooCommerce order
     * @param int $product_id Product ID
     * @return void
     */
    private function renderEmailTemplate(array $template_info, \WC_Order $order, int $product_id): void {
        // Handle database templates
        if (isset($template_info['source']) && $template_info['source'] === 'database') {
            $content = $template_info['content'];
            
            // Replace template variables
            $content = $this->replaceTemplateVariables($content, $order, $product_id, $template_info['type']);
            
            // Set email subject if available
            if (!empty($template_info['subject'])) {
                $subject = $this->replaceTemplateVariables($template_info['subject'], $order, $product_id, $template_info['type']);
                // Hook into WooCommerce email subject filters
                // Use a closure to capture the order ID and check if it matches
                $order_id = $order->get_id();
                add_filter('woocommerce_email_subject_customer_processing_order', function($default_subject, $email) use ($subject, $order_id) {
                    // Only apply if this is the correct order
                    if (isset($email->object) && is_a($email->object, 'WC_Order') && $email->object->get_id() === $order_id) {
                        return $subject;
                    }
                    return $default_subject;
                }, 10, 2);
                // Also try the general filter
                add_filter('woocommerce_email_subject_customer_completed_order', function($default_subject, $email) use ($subject, $order_id) {
                    if (isset($email->object) && is_a($email->object, 'WC_Order') && $email->object->get_id() === $order_id) {
                        return $subject;
                    }
                    return $default_subject;
                }, 10, 2);
            }
            
            echo $content;
            $this->helper->debug('OrderEmailCustomizer: Rendered database template', [
                'type' => $template_info['type'],
                'order_id' => $order->get_id(),
                'product_id' => $product_id
            ]);
            return;
        }
        
        // Handle file-based templates (existing logic)
        $file_path = $this->templateBasePath . $template_info['file'];
        
        if (!file_exists($file_path)) {
            $this->helper->debug('OrderEmailCustomizer: Template file not found', $file_path);
            return;
        }
        
        $email_body_content = file_get_contents($file_path);
        
        if ($email_body_content === false) {
            $this->helper->debug('OrderEmailCustomizer: Failed to read template file', $file_path);
            return;
        }
        
        // Insert dynamic data for event templates
        if ($template_info['dynamic_data']) {
            $email_body_content = $this->insertDynamicData($email_body_content, $order, $product_id);
        }
        
        echo $email_body_content;
        $this->helper->debug('OrderEmailCustomizer: Rendered email template', [
            'template' => $template_info['file'],
            'order_id' => $order->get_id(),
            'product_id' => $product_id
        ]);
    }
    
    /**
     * Replace template variables in content
     * 
     * @param string $content Email template content
     * @param \WC_Order $order WooCommerce order
     * @param int $product_id Product ID
     * @param string $template_type Template type (membership_new, membership_renewal, language_class, event_with_lunch, event_no_lunch)
     * @return string Content with variables replaced
     */
    private function replaceTemplateVariables(string $content, \WC_Order $order, int $product_id, string $template_type): string {
        // Get customer object if customer ID exists
        $customer_id = $order->get_customer_id();
        $customer = null;
        if ($customer_id > 0 && function_exists('wc_get_customer')) {
            $customer = wc_get_customer($customer_id);
        } elseif ($customer_id > 0) {
            // Fallback to WordPress user if WooCommerce customer function not available
            $customer = get_user_by('id', $customer_id);
        }
        
        $first_name = $order->get_billing_first_name() ?: ($customer ? ($customer->get_first_name() ?? ($customer->first_name ?? '')) : '');
        $last_name = $order->get_billing_last_name() ?: ($customer ? ($customer->get_last_name() ?? ($customer->last_name ?? '')) : '');
        
        $replacements = [
            '{first_name}' => $first_name,
            '{last_name}' => $last_name,
            '{order_id}' => (string) $order->get_id(),
            '{order_date}' => $order->get_date_created()->date_i18n('F j, Y'),
            '{order_total}' => $order->get_formatted_order_total(),
        ];
        
        // Add type-specific variables
        switch ($template_type) {
            case 'membership_new':
            case 'membership_renewal':
                $membership_level = '';
                foreach ($order->get_items() as $item) {
                    if (has_term('memberships', 'product_cat', $item->get_product_id())) {
                        $membership_level = $item->get_name();
                        break;
                    }
                }
                $replacements['{membership_level}'] = $membership_level;
                
                if ($template_type === 'membership_renewal') {
                    // Get renewal date from subscription or user meta
                    $renewal_date = '';
                    if (function_exists('wcs_get_subscription')) {
                        $subscriptions = wcs_get_subscriptions_for_order($order);
                        if (!empty($subscriptions)) {
                            $subscription = reset($subscriptions);
                            $renewal_date = $subscription->get_date('next_payment');
                            if ($renewal_date) {
                                $renewal_date = date_i18n('F j, Y', strtotime($renewal_date));
                            }
                        }
                    }
                    if (empty($renewal_date)) {
                        // Fallback: calculate from order date + 1 year
                        $renewal_date = date_i18n('F j, Y', strtotime('+1 year', $order->get_date_created()->getTimestamp()));
                    }
                    $replacements['{renewal_date}'] = $renewal_date;
                }
                break;
                
            case 'language_class':
                $class_name = '';
                foreach ($order->get_items() as $item) {
                    if (has_term('language-class', 'product_cat', $item->get_product_id())) {
                        $class_name = $item->get_name();
                        break;
                    }
                }
                $replacements['{class_name}'] = $class_name;
                break;
                
            case 'event_with_lunch':
            case 'event_no_lunch':
                $datetime_info = $this->getEventDateTimeInfo($product_id);
                $location_info = $this->getEventLocationInfo($product_id);
                $speaker_info = $this->getEventSpeakerInfo($product_id);
                
                $replacements['{event_name}'] = get_the_title($product_id) ?: 'Event';
                $replacements['{event_date}'] = $datetime_info['date'];
                $replacements['{event_time}'] = $datetime_info['time'];
                $replacements['{event_location}'] = $location_info['full_location'];
                $replacements['{speaker_name}'] = $speaker_info['name'];
                
                // Handle [Speaker Section] placeholder (legacy format)
                $content = $this->insertSpeakerSection($content, $speaker_info);
                break;
                
            case 'general':
                // Get product name from order items
                $product_name = '';
                foreach ($order->get_items() as $item) {
                    $product_name = $item->get_name();
                    break; // Use first product name
                }
                if (empty($product_name)) {
                    $product_name = get_the_title($product_id) ?: 'Product';
                }
                $replacements['{product_name}'] = $product_name;
                break;
        }
        
        // Replace all variables
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        
        return $content;
    }
    
    /**
     * Insert dynamic data into email template (legacy method for file-based templates)
     * 
     * @param string $email_body_content Email template content
     * @param \WC_Order $order WooCommerce order
     * @param int $product_id Product ID
     * @return string Email content with dynamic data
     */
    private function insertDynamicData(string $email_body_content, \WC_Order $order, int $product_id): string {
        // Get event datetime information
        $datetime_info = $this->getEventDateTimeInfo($product_id);
        
        // Get event location information
        $location_info = $this->getEventLocationInfo($product_id);
        
        // Get speaker information
        $speaker_info = $this->getEventSpeakerInfo($product_id);
        
        // Get attendee information
        $attendee_info = $this->getAttendeeInfo($order);
        
        // Get product name
        $product_name = get_the_title($product_id) ?: 'Event';
        
        // Replace basic placeholders (legacy format)
        $replacements = [
            '[Product Name]' => $product_name,
            '[Event Date]' => $datetime_info['date'],
            '[Event Time]' => $datetime_info['time'],
            '[Event Location]' => $location_info['full_location'],
        ];
        
        $email_body_content = str_replace(array_keys($replacements), array_values($replacements), $email_body_content);
        
        // Handle conditional speaker section
        $email_body_content = $this->insertSpeakerSection($email_body_content, $speaker_info);
        
        return $email_body_content;
    }
    
    /**
     * Get event datetime information
     * 
     * @param int $product_id Product ID
     * @return array DateTime information
     */
    private function getEventDateTimeInfo(int $product_id): array {
        $event_datetime = get_post_meta($product_id, '_ui_event_start_datetime', true);
        
        $result = [
            'date' => '',
            'time' => '',
            'timestamp' => $event_datetime
        ];
        
        if (!empty($event_datetime) && is_numeric($event_datetime)) {
            $result['date'] = date('F j, Y', $event_datetime); // e.g., August 27, 2024
            $result['time'] = date('g:i A', $event_datetime);   // e.g., 9:00 AM
        }
        
        return $result;
    }
    
    /**
     * Get event location information
     * 
     * @param int $product_id Product ID
     * @return array Location information
     */
    private function getEventLocationInfo(int $product_id): array {
        $location_name = get_post_meta($product_id, '_ui_event_location_name', true) ?: '';
        $location_address = get_post_meta($product_id, '_ui_event_location_address', true) ?: '';
        
        return [
            'name' => $location_name,
            'address' => $location_address,
            'full_location' => trim($location_name . ', ' . $location_address, ', ')
        ];
    }
    
    /**
     * Get event speaker information
     * 
     * @param int $product_id Product ID
     * @return array Speaker information
     */
    private function getEventSpeakerInfo(int $product_id): array {
        return [
            'name' => get_post_meta($product_id, '_ui_event_speaker_name', true) ?: '',
            'topic' => get_post_meta($product_id, '_ui_event_discussion_topic', true) ?: ''
        ];
    }
    
    /**
     * Get attendee information from order
     * 
     * @param \WC_Order $order WooCommerce order
     * @return array Attendee information
     */
    private function getAttendeeInfo(\WC_Order $order): array {
        return [
            'name' => $order->get_meta('attendee_name') ?: '',
            'email' => $order->get_meta('attendee_email') ?: ''
        ];
    }
    
    /**
     * Insert speaker section into email content
     * 
     * @param string $email_body_content Email content
     * @param array $speaker_info Speaker information
     * @return string Updated email content
     */
    private function insertSpeakerSection(string $email_body_content, array $speaker_info): string {
        if (!empty($speaker_info['name']) && !empty($speaker_info['topic'])) {
            $speaker_section = "<h2>Speaker:</h2>
                                <p>
                                    <strong>Name:</strong> {$speaker_info['name']}<br>
                                    <strong>Title of Discussion:</strong> {$speaker_info['topic']}
                                </p>";
            
            $email_body_content = str_replace('[Speaker Section]', $speaker_section, $email_body_content);
        } else {
            // Remove placeholder if no speaker info is available
            $email_body_content = str_replace('[Speaker Section]', '', $email_body_content);
        }
        
        return $email_body_content;
    }
    
    /**
     * Set template base path
     * 
     * @param string $path Base path for templates
     * @return void
     */
    public function setTemplateBasePath(string $path): void {
        $this->templateBasePath = rtrim($path, '/') . '/';
    }
    
    /**
     * Add special product template
     * 
     * @param int $product_id Product ID
     * @param string $template_file Template filename
     * @return void
     */
    public function addSpecialProductTemplate(int $product_id, string $template_file): void {
        $this->specialProductTemplates[$product_id] = $template_file;
        $this->helper->debug('OrderEmailCustomizer: Added special template', [
            'product_id' => $product_id,
            'template' => $template_file
        ]);
    }
    
    /**
     * Remove special product template
     * 
     * @param int $product_id Product ID
     * @return void
     */
    public function removeSpecialProductTemplate(int $product_id): void {
        if (isset($this->specialProductTemplates[$product_id])) {
            unset($this->specialProductTemplates[$product_id]);
            $this->helper->debug('OrderEmailCustomizer: Removed special template', $product_id);
        }
    }
    
    /**
     * Get available email templates
     * 
     * @return array Available templates
     */
    public function getAvailableTemplates(): array {
        $templates = [];
        $template_files = glob($this->templateBasePath . '*.html');
        
        foreach ($template_files as $file) {
            $templates[] = basename($file);
        }
        
        return $templates;
    }
    
    /**
     * Get customizer status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'woocommerce_available' => class_exists('WC_Order'),
            'subscriptions_available' => function_exists('wcs_order_contains_renewal'),
            'template_path' => $this->templateBasePath,
            'template_path_exists' => is_dir($this->templateBasePath),
            'available_templates' => $this->getAvailableTemplates(),
            'special_product_templates' => $this->specialProductTemplates
        ];
    }
}
