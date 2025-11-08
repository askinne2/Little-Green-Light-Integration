<?php
/**
 * Settings Manager Interface
 * 
 * Contract for the unified LGL settings management service.
 * Defines all methods for settings storage, validation, and integration.
 * 
 * @package UpstateInternational\LGL
 * @since 2.1.0
 */

namespace UpstateInternational\LGL\Admin;

/**
 * SettingsManagerInterface
 * 
 * Interface for unified settings management
 */
interface SettingsManagerInterface {
    
    /**
     * Get all settings with defaults applied
     * 
     * @return array Complete settings array with defaults
     */
    public function getAll(): array;
    
    /**
     * Get a single setting value
     * 
     * @param string $key Setting key
     * @param mixed $default Default value if setting not found
     * @return mixed Setting value or default
     */
    public function get(string $key, $default = null);
    
    /**
     * Update multiple settings at once
     * 
     * @param array $settings Settings to update
     * @return bool True on success, false on failure
     */
    public function update(array $settings): bool;
    
    /**
     * Update a single setting
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value): bool;
    
    /**
     * Validate settings before save
     * 
     * @param array $settings Settings to validate
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate(array $settings): array;
    
    /**
     * Get settings schema with validation rules
     * 
     * @return array Complete schema definition
     */
    public function getSchema(): array;
    
    /**
     * Test API connection with current or provided credentials
     * 
     * @param string|null $apiUrl Optional API URL to test
     * @param string|null $apiKey Optional API key to test
     * @return array ['success' => bool, 'message' => string, 'data' => array]
     */
    public function testConnection(?string $apiUrl = null, ?string $apiKey = null): array;
    
    /**
     * Import membership levels from LGL API
     * 
     * @return array ['success' => bool, 'levels' => array, 'message' => string]
     */
    public function importMembershipLevels(): array;
    
    /**
     * Reset all settings to defaults
     * 
     * @return bool True on success, false on failure
     */
    public function reset(): bool;
    
    /**
     * Export settings as JSON
     * 
     * @return string JSON encoded settings
     */
    public function export(): string;
    
    /**
     * Import settings from JSON
     * 
     * @param string $json JSON encoded settings
     * @return array ['success' => bool, 'message' => string, 'imported' => int]
     */
    public function import(string $json): array;
}

