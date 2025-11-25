<?php
/**
 * Async Family Member Processor
 * 
 * Handles asynchronous LGL API processing for family member registrations.
 * Processes LGL sync in background to speed up form submission.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder;

use UpstateInternational\LGL\LGL\Helper;
use UpstateInternational\LGL\Memberships\MembershipRegistrationService;
use UpstateInternational\LGL\LGL\Connection;

/**
 * AsyncFamilyMemberProcessor Class
 * 
 * Handles background processing of LGL API calls for family member registrations
 */
class AsyncFamilyMemberProcessor {
    
    /**
     * Cron hook name for async processing (creation)
     */
    const CRON_HOOK = 'lgl_process_family_member_async';
    
    /**
     * Cron hook name for async processing (deactivation)
     */
    const CRON_HOOK_DEACTIVATION = 'lgl_process_family_member_deactivation_async';
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * Constructor
     * 
     * @param Helper $helper LGL helper service
     * @param Connection $connection LGL connection service
     */
    public function __construct(
        Helper $helper,
        Connection $connection
    ) {
        $this->helper = $helper;
        $this->connection = $connection;
        
        // Register WP Cron handlers
        add_action(self::CRON_HOOK, [$this, 'handleAsyncRequest'], 10, 1);
        add_action(self::CRON_HOOK_DEACTIVATION, [$this, 'handleAsyncDeactivationRequest'], 10, 2); // 2 args: parent_user_id, child_user_id
    }
    
    /**
     * Schedule async LGL processing for a family member
     * 
     * Uses WP Cron to process LGL sync in background
     * 
     * @param int $child_user_id Child WordPress user ID
     * @param int $parent_user_id Parent WordPress user ID
     * @param array $context Context data for MembershipRegistrationService
     * @return void
     */
    public function scheduleAsyncProcessing(int $child_user_id, int $parent_user_id, array $context): void {
        $this->helper->debug('LGL AsyncFamilyMemberProcessor: Scheduling async processing', [
            'child_user_id' => $child_user_id
        ]);
        
        // Store context data in user meta for async processing
        update_user_meta($child_user_id, '_lgl_async_queued', true);
        update_user_meta($child_user_id, '_lgl_async_queued_at', current_time('mysql'));
        update_user_meta($child_user_id, '_lgl_async_parent_id', $parent_user_id);
        update_user_meta($child_user_id, '_lgl_async_context', $context);
        
        // Schedule WP Cron event to run immediately (on next page load)
        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK, [$child_user_id]);
        
        if ($scheduled === false) {
            // Check if already scheduled (prevents duplicates)
            $next_scheduled = wp_next_scheduled(self::CRON_HOOK, [$child_user_id]);
            if (!$next_scheduled) {
                $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to schedule cron', [
                    'child_user_id' => $child_user_id
                ]);
            }
        } else {
            // Trigger cron immediately (non-blocking) to ensure it runs
            // This uses WordPress's spawn_cron() which makes a non-blocking HTTP request
            if (!defined('DISABLE_WP_CRON') || !constant('DISABLE_WP_CRON')) {
                spawn_cron();
            }
        }
    }
    
    /**
     * Handle async request (called by WP Cron)
     * 
     * @param int $child_user_id Child WordPress user ID (passed by WP Cron)
     * @return void
     */
    public function handleAsyncRequest(int $child_user_id): void {
        $this->helper->info('LGL AsyncFamilyMemberProcessor: Processing family member async', [
            'child_user_id' => $child_user_id
        ]);
        
        if (!$child_user_id || $child_user_id <= 0) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Invalid child user ID', [
                'child_user_id' => $child_user_id
            ]);
            return;
        }
        
        // Check if already processed (prevent duplicate processing)
        $already_processed = get_user_meta($child_user_id, '_lgl_async_processed', true);
        if ($already_processed) {
            $this->helper->info('LGL AsyncFamilyMemberProcessor: Family member already processed, skipping', [
                'child_user_id' => $child_user_id
            ]);
            return;
        }
        
        // Get stored context data
        $parent_user_id = (int) get_user_meta($child_user_id, '_lgl_async_parent_id', true);
        $context = get_user_meta($child_user_id, '_lgl_async_context', true);
        
        if (!$parent_user_id || empty($context)) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Missing context data', [
                'child_user_id' => $child_user_id,
                'parent_user_id' => $parent_user_id
            ]);
            return;
        }
        
        try {
            // Get MembershipRegistrationService from container
            $container = \UpstateInternational\LGL\Core\ServiceContainer::getInstance();
            if (!$container->has('memberships.registration_service')) {
                throw new \Exception('MembershipRegistrationService not available');
            }
            
            $registrationService = $container->get('memberships.registration_service');
            
            // Process LGL registration
            $result = $registrationService->register($context);
            
            // Create LGL constituent relationship if registration succeeded
            if (!empty($result['lgl_id'])) {
                $child_lgl_id = (int) $result['lgl_id'];
                $this->createLGLRelationship($parent_user_id, $child_user_id, $child_lgl_id);
            }
            
            // Mark as processed
            update_user_meta($child_user_id, '_lgl_async_processed', true);
            update_user_meta($child_user_id, '_lgl_async_processed_at', current_time('mysql'));
            
            // Clean up queued meta
            delete_user_meta($child_user_id, '_lgl_async_queued');
            delete_user_meta($child_user_id, '_lgl_async_context');
            
            $this->helper->info('LGL AsyncFamilyMemberProcessor: Async processing completed', [
                'child_user_id' => $child_user_id,
                'lgl_id' => $result['lgl_id'] ?? null
            ]);
            
        } catch (\Exception $e) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Async processing failed', [
                'child_user_id' => $child_user_id,
                'error' => $e->getMessage()
            ]);
            
            // Mark as failed for retry
            update_user_meta($child_user_id, '_lgl_async_failed', true);
            update_user_meta($child_user_id, '_lgl_async_error', $e->getMessage());
            update_user_meta($child_user_id, '_lgl_async_failed_at', current_time('mysql'));
            
            // Re-schedule for retry (in 5 minutes)
            wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_HOOK, [$child_user_id]);
            
            $this->helper->warning('LGL AsyncFamilyMemberProcessor: Re-scheduled for retry', [
                'child_user_id' => $child_user_id
            ]);
        }
    }
    
    /**
     * Create LGL constituent relationships (Parent/Child - both directions)
     * 
     * @param int $parent_user_id Parent WordPress user ID
     * @param int $child_user_id Child WordPress user ID
     * @param int $child_lgl_id Child LGL constituent ID
     * @return void
     */
    private function createLGLRelationship(int $parent_user_id, int $child_user_id, int $child_lgl_id): void {
        $parent_lgl_id = get_user_meta($parent_user_id, 'lgl_id', true);
        
        if (!$parent_lgl_id) {
            $this->helper->warning('LGL AsyncFamilyMemberProcessor: Parent LGL ID not found, skipping relationship creation', [
                'parent_user_id' => $parent_user_id
            ]);
            return;
        }
        
        // Get relationship type IDs
        $parent_type_id = $this->connection->getRelationshipTypeId('Parent');
        $child_type_id = $this->connection->getRelationshipTypeId('Child');
        
        if (!$parent_type_id || !$child_type_id) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Relationship types not found', [
                'parent_type_id' => $parent_type_id,
                'child_type_id' => $child_type_id
            ]);
            return;
        }
        
        // Create Child -> Parent relationship
        $child_to_parent = $this->connection->createConstituentRelationship((string) $child_lgl_id, [
            'related_constituent_id' => (int) $parent_lgl_id,
            'relationship_type_id' => $parent_type_id
        ]);
        
        if (!empty($child_to_parent['success']) && !empty($child_to_parent['data']['id'])) {
            update_user_meta($child_user_id, 'lgl_family_relationship_id', $child_to_parent['data']['id']);
        } else {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to create Child->Parent relationship', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id,
                'error' => $child_to_parent['error'] ?? 'Unknown error'
            ]);
        }
        
        // Create Parent -> Child relationship
        $parent_to_child = $this->connection->createConstituentRelationship((string) $parent_lgl_id, [
            'related_constituent_id' => $child_lgl_id,
            'relationship_type_id' => $child_type_id
        ]);
        
        if (!empty($parent_to_child['success']) && !empty($parent_to_child['data']['id'])) {
            update_user_meta($child_user_id, 'lgl_family_relationship_parent_id', $parent_to_child['data']['id']);
            $this->helper->info('LGL AsyncFamilyMemberProcessor: Created LGL family relationships', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
        } else {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to create Parent->Child relationship', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id,
                'error' => $parent_to_child['error'] ?? 'Unknown error'
            ]);
        }
    }
    
    /**
     * Schedule async LGL relationship deletion for a family member deactivation
     * 
     * Uses WP Cron to process LGL relationship deletion in background
     * 
     * @param int $child_user_id Child WordPress user ID (may be deleted already)
     * @param int $parent_user_id Parent WordPress user ID
     * @param array $relationship_ids Array of relationship IDs to delete ['child_to_parent' => id, 'parent_to_child' => id]
     * @return void
     */
    public function scheduleAsyncDeactivation(int $child_user_id, int $parent_user_id, array $relationship_ids): void {
        $this->helper->debug('LGL AsyncFamilyMemberProcessor: Scheduling async deactivation', [
            'child_user_id' => $child_user_id
        ]);
        
        // Store deactivation data in parent user meta (child may be deleted)
        $deactivation_key = '_lgl_async_deactivation_' . $child_user_id;
        update_user_meta($parent_user_id, $deactivation_key, [
            'child_user_id' => $child_user_id,
            'parent_user_id' => $parent_user_id,
            'relationship_ids' => $relationship_ids,
            'queued_at' => current_time('mysql')
        ]);
        
        // Schedule WP Cron event to run immediately (on next page load)
        $scheduled = wp_schedule_single_event(time(), self::CRON_HOOK_DEACTIVATION, [$parent_user_id, $child_user_id]);
        
        if ($scheduled === false) {
            $next_scheduled = wp_next_scheduled(self::CRON_HOOK_DEACTIVATION, [$parent_user_id, $child_user_id]);
            if (!$next_scheduled) {
                $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to schedule deactivation cron', [
                    'child_user_id' => $child_user_id
                ]);
            }
        } else {
            // Trigger cron immediately (non-blocking) to ensure it runs
            // This uses WordPress's spawn_cron() which makes a non-blocking HTTP request
            if (!defined('DISABLE_WP_CRON') || !constant('DISABLE_WP_CRON')) {
                spawn_cron();
            }
        }
    }
    
    /**
     * Handle async deactivation request (called by WP Cron)
     * 
     * @param int $parent_user_id Parent WordPress user ID
     * @param int $child_user_id Child WordPress user ID (may be deleted already)
     * @return void
     */
    public function handleAsyncDeactivationRequest(int $parent_user_id, int $child_user_id): void {
        $this->helper->info('LGL AsyncFamilyMemberProcessor: Processing family member deactivation async', [
            'child_user_id' => $child_user_id,
            'parent_user_id' => $parent_user_id
        ]);
        
        if (!$child_user_id || $child_user_id <= 0 || !$parent_user_id || $parent_user_id <= 0) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Invalid user IDs', [
                'child_user_id' => $child_user_id,
                'parent_user_id' => $parent_user_id
            ]);
            return;
        }
        
        // Check if already processed
        $deactivation_key = '_lgl_async_deactivation_' . $child_user_id;
        $deactivation_data = get_user_meta($parent_user_id, $deactivation_key, true);
        
        if (empty($deactivation_data)) {
            // Check if it was already processed
            $processed_key = '_lgl_async_deactivation_processed_' . $child_user_id;
            $processed_data = get_user_meta($parent_user_id, $processed_key, true);
            
            if (!empty($processed_data)) {
                $this->helper->info('LGL AsyncFamilyMemberProcessor: Deactivation already processed, skipping', [
                    'child_user_id' => $child_user_id
                ]);
            }
            return;
        }
        
        $relationship_ids = $deactivation_data['relationship_ids'] ?? [];
        
        try {
            // Delete LGL relationships
            $deleted_count = 0;
            
            // Delete Child -> Parent relationship
            if (!empty($relationship_ids['child_to_parent'])) {
                $response = $this->connection->deleteConstituentRelationship((int) $relationship_ids['child_to_parent']);
                if ($response['success']) {
                    $deleted_count++;
                } else {
                    $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to delete Child->Parent relationship', [
                        'relationship_id' => $relationship_ids['child_to_parent'],
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
            // Delete Parent -> Child relationship
            if (!empty($relationship_ids['parent_to_child'])) {
                $response = $this->connection->deleteConstituentRelationship((int) $relationship_ids['parent_to_child']);
                if ($response['success']) {
                    $deleted_count++;
                } else {
                    $this->helper->error('LGL AsyncFamilyMemberProcessor: Failed to delete Parent->Child relationship', [
                        'relationship_id' => $relationship_ids['parent_to_child'],
                        'error' => $response['error'] ?? 'Unknown error'
                    ]);
                }
            }
            
            // Fallback: Query and delete if stored IDs didn't work
            if ($deleted_count < 2) {
                $this->deleteRelationshipsFallback($child_user_id, $parent_user_id);
            }
            
            // Mark as processed
            delete_user_meta($parent_user_id, $deactivation_key);
            update_user_meta($parent_user_id, '_lgl_async_deactivation_processed_' . $child_user_id, [
                'processed_at' => current_time('mysql'),
                'deleted_count' => $deleted_count
            ]);
            
            $this->helper->info('LGL AsyncFamilyMemberProcessor: Async deactivation completed', [
                'child_user_id' => $child_user_id,
                'relationships_deleted' => $deleted_count
            ]);
            
        } catch (\Exception $e) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Async deactivation failed', [
                'child_user_id' => $child_user_id,
                'error' => $e->getMessage()
            ]);
            
            // Re-schedule for retry (in 5 minutes)
            wp_schedule_single_event(time() + (5 * MINUTE_IN_SECONDS), self::CRON_HOOK_DEACTIVATION, [$parent_user_id, $child_user_id]);
            
            $this->helper->warning('LGL AsyncFamilyMemberProcessor: Re-scheduled deactivation for retry', [
                'child_user_id' => $child_user_id
            ]);
        }
    }
    
    /**
     * Fallback method to query and delete relationships if stored IDs didn't work
     * 
     * @param int $child_user_id Child WordPress user ID
     * @param int $parent_user_id Parent WordPress user ID
     * @return void
     */
    private function deleteRelationshipsFallback(int $child_user_id, int $parent_user_id): void {
        // Try to get LGL IDs from user meta (child may still exist)
        $child_lgl_id = get_user_meta($child_user_id, 'lgl_id', true);
        $parent_lgl_id = get_user_meta($parent_user_id, 'lgl_id', true);
        
        if (!$child_lgl_id || !$parent_lgl_id) {
            $this->helper->warning('LGL AsyncFamilyMemberProcessor: Cannot query relationships - missing LGL IDs', [
                'child_lgl_id' => $child_lgl_id,
                'parent_lgl_id' => $parent_lgl_id
            ]);
            return;
        }
        
        // Query child's relationships
        try {
            $child_relationships = $this->connection->getConstituentRelationships((int) $child_lgl_id);
            if ($child_relationships['success'] && isset($child_relationships['data'])) {
                $relationships = $this->extractRelationshipsFromResponse($child_relationships['data']);
                foreach ($relationships as $rel) {
                    $rel_id = is_object($rel) ? ($rel->id ?? null) : ($rel['id'] ?? null);
                    $rel_type = is_object($rel) ? 
                        ($rel->relationship_type_name ?? null) : 
                        ($rel['relationship_type_name'] ?? null);
                    
                    if ($rel_id && $rel_type && stripos($rel_type, 'Parent') !== false) {
                        $this->connection->deleteConstituentRelationship((int) $rel_id);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Error querying child relationships', [
                'error' => $e->getMessage()
            ]);
        }
        
        // Query parent's relationships
        try {
            $parent_relationships = $this->connection->getConstituentRelationships((int) $parent_lgl_id);
            if ($parent_relationships['success'] && isset($parent_relationships['data'])) {
                $relationships = $this->extractRelationshipsFromResponse($parent_relationships['data']);
                foreach ($relationships as $rel) {
                    $rel_id = is_object($rel) ? ($rel->id ?? null) : ($rel['id'] ?? null);
                    $rel_type = is_object($rel) ? 
                        ($rel->relationship_type_name ?? null) : 
                        ($rel['relationship_type_name'] ?? null);
                    $related_id = is_object($rel) ? 
                        ($rel->related_constituent_id ?? null) : 
                        ($rel['related_constituent_id'] ?? null);
                    
                    if ($rel_id && $rel_type && stripos($rel_type, 'Child') !== false && 
                        $related_id && (int)$related_id === (int)$child_lgl_id) {
                        $this->connection->deleteConstituentRelationship((int) $rel_id);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->helper->error('LGL AsyncFamilyMemberProcessor: Error querying parent relationships', [
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Extract relationships from LGL API response
     * 
     * @param mixed $data Response data
     * @return array Array of relationship objects/arrays
     */
    private function extractRelationshipsFromResponse($data): array {
        if (is_array($data)) {
            if (isset($data['items']) && is_array($data['items'])) {
                return $data['items'];
            }
            if (!empty($data) && (isset($data[0]['id']) || (is_object($data[0]) && isset($data[0]->id)))) {
                return $data;
            }
        } elseif (is_object($data)) {
            if (isset($data->items) && is_array($data->items)) {
                return $data->items;
            }
            if (isset($data->id)) {
                return [$data];
            }
        }
        
        return [];
    }
}

