<?php
/**
 * JetFormBuilder Action Interface
 * 
 * Defines the contract for all JetFormBuilder custom actions.
 * Ensures consistent implementation across all action classes.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

/**
 * JetFormActionInterface
 * 
 * Interface for JetFormBuilder custom actions
 */
interface JetFormActionInterface {
    
    /**
     * Handle the action execution
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void;
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string;
    
    /**
     * Get action priority (optional)
     * 
     * @return int
     */
    public function getPriority(): int;
    
    /**
     * Get number of accepted arguments (optional)
     * 
     * @return int
     */
    public function getAcceptedArgs(): int;
    
    /**
     * Validate request data before processing
     * 
     * @param array $request Form data
     * @return bool
     */
    public function validateRequest(array $request): bool;
    
    /**
     * Get required fields for this action
     * 
     * @return array<string>
     */
    public function getRequiredFields(): array;
}
