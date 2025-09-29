<?php
/**
 * Check Order Handler
 * 
 * Handles processing of check/offline payment orders in WooCommerce.
 * Automatically processes orders with bank transfer, check, or COD payment methods.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\WooCommerce;

use UpstateInternational\LGL\LGL\Helper;

/**
 * CheckOrderHandler Class
 * 
 * Processes orders with offline payment methods
 */
class CheckOrderHandler {
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Order Processor service
     * 
     * @var OrderProcessor
     */
    private OrderProcessor $orderProcessor;
    
    /**
     * Supported offline payment methods
     * 
     * @var array<string>
     */
    private array $offlinePaymentMethods = [
        'bacs',   // Bank Transfer
        'cheque', // Check
        'cod'     // Cash on Delivery
    ];
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param OrderProcessor $orderProcessor Order processor service
     */
    public function __construct(Helper $helper, OrderProcessor $orderProcessor) {
        $this->helper = $helper;
        $this->orderProcessor = $orderProcessor;
    }
    
    /**
     * Process check/offline payment orders
     * 
     * Automatically processes orders that use offline payment methods
     * 
     * @param int $order_id WooCommerce order ID
     * @return void
     */
    public function processCheckOrder(int $order_id): void {
        if (!class_exists('WC_Order')) {
            $this->helper->debug('CheckOrderHandler: WooCommerce not available');
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->helper->debug('CheckOrderHandler: Order not found', $order_id);
            return;
        }
        
        $payment_method = $order->get_payment_method();
        
        if ($this->isOfflinePaymentMethod($payment_method)) {
            $this->helper->debug('CheckOrderHandler: Processing offline payment order', [
                'order_id' => $order_id,
                'payment_method' => $payment_method
            ]);
            
            // Process the order through the main order processor
            $this->orderProcessor->processCompletedOrder($order_id);
            
        } else {
            $this->helper->debug('CheckOrderHandler: Not an offline payment order - skipping', [
                'order_id' => $order_id,
                'payment_method' => $payment_method
            ]);
        }
    }
    
    /**
     * Check if payment method is offline
     * 
     * @param string $payment_method Payment method slug
     * @return bool
     */
    private function isOfflinePaymentMethod(string $payment_method): bool {
        return in_array($payment_method, $this->offlinePaymentMethods);
    }
    
    /**
     * Get supported offline payment methods
     * 
     * @return array<string>
     */
    public function getOfflinePaymentMethods(): array {
        return $this->offlinePaymentMethods;
    }
    
    /**
     * Add offline payment method
     * 
     * @param string $method_slug Payment method slug
     * @return void
     */
    public function addOfflinePaymentMethod(string $method_slug): void {
        if (!in_array($method_slug, $this->offlinePaymentMethods)) {
            $this->offlinePaymentMethods[] = $method_slug;
            $this->helper->debug('CheckOrderHandler: Added offline payment method', $method_slug);
        }
    }
    
    /**
     * Remove offline payment method
     * 
     * @param string $method_slug Payment method slug
     * @return void
     */
    public function removeOfflinePaymentMethod(string $method_slug): void {
        $key = array_search($method_slug, $this->offlinePaymentMethods);
        if ($key !== false) {
            unset($this->offlinePaymentMethods[$key]);
            $this->offlinePaymentMethods = array_values($this->offlinePaymentMethods); // Re-index
            $this->helper->debug('CheckOrderHandler: Removed offline payment method', $method_slug);
        }
    }
    
    /**
     * Check if order needs processing
     * 
     * @param int $order_id WooCommerce order ID
     * @return bool
     */
    public function shouldProcessOrder(int $order_id): bool {
        if (!class_exists('WC_Order')) {
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        return $this->isOfflinePaymentMethod($order->get_payment_method());
    }
    
    /**
     * Get handler status
     * 
     * @return array Status information
     */
    public function getStatus(): array {
        return [
            'woocommerce_available' => class_exists('WC_Order'),
            'offline_payment_methods' => $this->offlinePaymentMethods,
            'processor_available' => isset($this->orderProcessor)
        ];
    }
    
    /**
     * Validate order for processing
     * 
     * @param int $order_id WooCommerce order ID
     * @return array Validation result
     */
    public function validateOrder(int $order_id): array {
        $result = [
            'valid' => false,
            'errors' => [],
            'order_id' => $order_id
        ];
        
        if (!class_exists('WC_Order')) {
            $result['errors'][] = 'WooCommerce not available';
            return $result;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            $result['errors'][] = 'Order not found';
            return $result;
        }
        
        $payment_method = $order->get_payment_method();
        if (!$this->isOfflinePaymentMethod($payment_method)) {
            $result['errors'][] = "Payment method '{$payment_method}' is not an offline method";
            return $result;
        }
        
        $result['valid'] = true;
        $result['payment_method'] = $payment_method;
        
        return $result;
    }
}
