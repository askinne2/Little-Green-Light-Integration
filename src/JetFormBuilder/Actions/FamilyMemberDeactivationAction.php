<?php
/**
 * Family Member Deactivation Action
 * 
 * Handles family member deactivation/removal through JetFormBuilder forms.
 * Removes family members from parent accounts and syncs family slot counts.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\JetFormBuilder\Actions;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;

/**
 * FamilyMemberDeactivationAction Class
 * 
 * Handles family member removal and relationship cleanup
 */
class FamilyMemberDeactivationAction implements JetFormActionInterface {
    
    /**
     * LGL Connection service
     * 
     * @var Connection
     */
    private Connection $connection;
    
    /**
     * LGL Helper service
     * 
     * @var Helper
     */
    private Helper $helper;
    
    /**
     * Constructor
     * 
     * @param Connection $connection LGL connection service
     * @param Helper $helper LGL helper service
     */
    public function __construct(Connection $connection, Helper $helper) {
        $this->connection = $connection;
        $this->helper = $helper;
    }
    
    /**
     * Get action name for registration
     * 
     * @return string
     */
    public function getName(): string {
        return 'ui_family_user_deactivation';
    }
    
    /**
     * Get action description
     * 
     * @return string
     */
    public function getDescription(): string {
        return 'Deactivates and removes family members from parent accounts';
    }
    
    /**
     * Get action priority
     * 
     * @return int
     */
    public function getPriority(): int {
        return 13;
    }
    
    /**
     * Get number of accepted arguments
     * 
     * @return int
     */
    public function getAcceptedArgs(): int {
        return 2;
    }
    
    /**
     * Get required fields for this action
     * 
     * @return array<string>
     */
    public function getRequiredFields(): array {
        return ['parent_user_id', 'child_users'];
    }
    
    /**
     * Validate request data before processing
     * 
     * @param array $request Form data
     * @return bool
     */
    public function validateRequest(array $request): bool {
        // Check required fields
        if (empty($request['parent_user_id'])) {
            $this->helper->debug('FamilyMemberDeactivationAction: Missing parent_user_id');
            return false;
        }
        
        if (empty($request['child_users'])) {
            $this->helper->debug('FamilyMemberDeactivationAction: Missing child_users');
            return false;
        }
        
        // Validate parent_user_id is numeric
        $parent_id = (int) $request['parent_user_id'];
        if ($parent_id <= 0) {
            $this->helper->debug('FamilyMemberDeactivationAction: Invalid parent_user_id', ['parent_id' => $parent_id]);
            return false;
        }
        
        // Verify parent user exists
        $parent_user = get_user_by('ID', $parent_id);
        if (!$parent_user) {
            $this->helper->debug('FamilyMemberDeactivationAction: Parent user not found', ['parent_id' => $parent_id]);
            return false;
        }
        
        // Verify parent has correct role
        if (!in_array('ui_patron_owner', $parent_user->roles)) {
            $this->helper->debug('FamilyMemberDeactivationAction: Parent does not have ui_patron_owner role', [
                'parent_id' => $parent_id,
                'roles' => $parent_user->roles
            ]);
            return false;
        }
        
        // Verify user is logged in
        if (!is_user_logged_in()) {
            $this->helper->debug('FamilyMemberDeactivationAction: User not logged in');
            return false;
        }
        
        return true;
    }
    
    /**
     * Handle family member deactivation action
     * 
     * @param array $request Form data from JetFormBuilder
     * @param mixed $action_handler JetFormBuilder action handler
     * @return void
     */
    public function handle(array $request, $action_handler): void {
        try {
        $this->helper->debug('FamilyMemberDeactivationAction: FUNCTION CALLED', [
            'request_keys' => array_keys($request),
            'is_user_logged_in' => is_user_logged_in(),
            'current_user_id' => get_current_user_id()
        ]);
        
        // Validate request
        if (!$this->validateRequest($request)) {
            $this->helper->debug('FamilyMemberDeactivationAction: Validation failed');
                $error_message = 'Invalid request data. Please check that all required fields are filled correctly.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $parent_id = (int) $request['parent_user_id'];
        $children_ids = $this->parseChildUsers($request['child_users']);
        
        if (empty($children_ids)) {
            $this->helper->debug('FamilyMemberDeactivationAction: No valid child user IDs to remove');
                $error_message = 'No family members selected for removal. Please select at least one family member.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $this->helper->debug('FamilyMemberDeactivationAction: Processing removal', [
            'parent_id' => $parent_id,
            'child_users' => $children_ids
        ]);
        
        // Get JetEngine relation
        if (!function_exists('jet_engine')) {
            $this->helper->debug('FamilyMemberDeactivationAction: JetEngine not available');
                $error_message = 'System error: JetEngine plugin is required but not available. Please contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        $relation = jet_engine()->relations->get_active_relations(24);
        if (!$relation) {
            $this->helper->debug('FamilyMemberDeactivationAction: Could not get JetEngine relation 24');
                $error_message = 'System error: Could not access family relationship data. Please contact support.';
                
                if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                    throw new \Jet_Form_Builder\Exceptions\Action_Exception($error_message);
                } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                    throw new \JFB_Modules\Actions\V2\Action_Exception($error_message);
                } else {
                    throw new \RuntimeException($error_message);
                }
        }
        
        // Get actual child relations to verify they exist
        $actual_child_relations = $this->getChildRelations($parent_id, $relation);
        $this->helper->debug('FamilyMemberDeactivationAction: Actual child relations', [
            'parent_id' => $parent_id,
            'actual_children' => $actual_child_relations,
            'requested_children' => $children_ids
        ]);
        
        // Remove only the selected children
        $removed_count = 0;
        foreach ($children_ids as $child_id) {
            $child_id = (int) $child_id;
            
            // Verify this child actually belongs to this parent
            if (!in_array($child_id, $actual_child_relations)) {
                $this->helper->debug('FamilyMemberDeactivationAction: Child does not belong to parent', [
                    'child_id' => $child_id,
                    'parent_id' => $parent_id
                ]);
                continue;
            }
            
            $this->helper->debug('FamilyMemberDeactivationAction: Removing Child', [
                'child_id' => $child_id,
                'parent_id' => $parent_id
            ]);
            
            // Delete LGL constituent relationships (both directions) BEFORE deleting the user
            // This ensures we can still access the relationship IDs from user meta
            $this->deleteLGLRelationship($child_id, $parent_id);
            
            // Deactivate/delete the child user
            $this->deactivateUser($child_id);
            
            // Remove the JetEngine relationship
            $this->removeRelationship($relation, $parent_id, $child_id);
            
            $removed_count++;
        }
        
        $this->helper->debug('FamilyMemberDeactivationAction: Removed children count', [
            'removed_count' => $removed_count,
            'requested_count' => count($children_ids)
        ]);
        
        // Final sync after all removals
        $this->syncFamilySlots($parent_id);
            
        } catch (\Exception $e) {
            $this->helper->debug('FamilyMemberDeactivationAction: Error occurred', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // Re-throw Action_Exception, convert others
            if ($e instanceof \Jet_Form_Builder\Exceptions\Action_Exception || 
                $e instanceof \JFB_Modules\Actions\V2\Action_Exception) {
                throw $e;
            }
            
            // Convert to Action_Exception if possible
            if (class_exists('\Jet_Form_Builder\Exceptions\Action_Exception')) {
                throw new \Jet_Form_Builder\Exceptions\Action_Exception($e->getMessage());
            } elseif (class_exists('\JFB_Modules\Actions\V2\Action_Exception')) {
                throw new \JFB_Modules\Actions\V2\Action_Exception($e->getMessage());
            } else {
                throw $e;
            }
        }
    }
    
    /**
     * Parse child_users field (handles JSON, array, or comma-separated)
     * 
     * @param mixed $child_users Raw child_users data
     * @return array<int> Array of child user IDs
     */
    private function parseChildUsers($child_users): array {
        // Handle JSON string (from JetFormBuilder)
        if (is_string($child_users)) {
            $decoded = json_decode($child_users, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $child_users = $decoded;
            } else {
                // If not JSON, try to parse as comma-separated or single value
                $child_users = array_filter(array_map('trim', explode(',', $child_users)));
            }
        }
        
        // Ensure it's an array
        if (!is_array($child_users)) {
            $child_users = [$child_users];
        }
        
        // Convert all to integers and filter out invalid values
        return array_map('intval', array_filter($child_users, function($id) {
            return is_numeric($id) && (int)$id > 0;
        }));
    }
    
    /**
     * Get child relations for a parent user
     * 
     * @param int $parent_id Parent user ID
     * @param object $relation JetEngine relation object
     * @return array<int> Array of child user IDs
     */
    private function getChildRelations(int $parent_id, $relation): array {
        try {
            $children = $relation->get_children($parent_id, 'ids');
            return is_array($children) ? array_map('intval', $children) : [];
        } catch (\Exception $e) {
            $this->helper->debug('FamilyMemberDeactivationAction: Error getting child relations', [
                'error' => $e->getMessage(),
                'parent_id' => $parent_id
            ]);
            return [];
        }
    }
    
    /**
     * Delete a user account
     * 
     * @param int $user_id User ID to delete
     * @return void
     */
    private function deactivateUser(int $user_id): void {
        // Delete any posts/content created by this user
        $user_posts = get_posts([
            'author' => $user_id,
            'posts_per_page' => -1,
            'post_type' => 'any'
        ]);
        
        foreach ($user_posts as $post) {
            wp_delete_post($post->ID, true); // Force delete (bypass trash)
        }
        
        // Delete the user account
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        wp_delete_user($user_id);
        
        $this->helper->debug('FamilyMemberDeactivationAction: User deleted', [
            'user_id' => $user_id,
            'posts_deleted' => count($user_posts)
        ]);
    }
    
    /**
     * Remove JetEngine relationship between parent and child
     * 
     * @param object $relation JetEngine relation object
     * @param int $parent_id Parent user ID
     * @param int $child_id Child user ID
     * @return void
     */
    private function removeRelationship($relation, int $parent_id, int $child_id): void {
        try {
            // Delete the relationship rows
            $relation->delete_rows($parent_id, $child_id, true);
            
            // Sync slots immediately after removal
            $this->helper->syncUsedFamilySlotsMeta($parent_id);
            
            $total_purchased = (int) get_user_meta($parent_id, 'user_total_family_slots_purchased', true);
            $actual_used = $this->helper->getActualUsedFamilySlots($parent_id);
            $new_available = $total_purchased - $actual_used;
            update_user_meta($parent_id, 'user_available_family_slots', max(0, $new_available));
            
            $this->helper->debug('FamilyMemberDeactivationAction: Relationship removed and slots synced', [
                'parent_id' => $parent_id,
                'child_id' => $child_id,
                'total_purchased' => $total_purchased,
                'actual_used' => $actual_used,
                'new_available' => $new_available
            ]);
        } catch (\Exception $e) {
            $this->helper->debug('FamilyMemberDeactivationAction: Error removing relationship', [
                'error' => $e->getMessage(),
                'parent_id' => $parent_id,
                'child_id' => $child_id
            ]);
        }
    }
    
    /**
     * Delete LGL constituent relationships (both directions)
     * 
     * Deletes both Parent/Child relationships in LGL CRM:
     * 1. Child -> Parent relationship (from child's perspective)
     * 2. Parent -> Child relationship (from parent's perspective)
     * 
     * First tries to use stored relationship IDs from user meta, then falls back to API queries.
     * 
     * WordPress User Meta Fields:
     *   - 'lgl_family_relationship_id': Child->Parent relationship ID
     *   - 'lgl_family_relationship_parent_id': Parent->Child relationship ID
     * 
     * @param int $child_uid Child WordPress user ID
     * @param int $parent_uid Parent WordPress user ID (optional, for querying parent relationships)
     * @return void
     */
    private function deleteLGLRelationship(int $child_uid, int $parent_uid = 0): void {
        $child_lgl_id = get_user_meta($child_uid, 'lgl_id', true);
        $parent_lgl_id = $parent_uid ? get_user_meta($parent_uid, 'lgl_id', true) : null;
        
        if (!$child_lgl_id) {
            $this->helper->debug('FamilyMemberDeactivationAction: Child LGL ID not found, skipping relationship deletion', [
                'child_uid' => $child_uid
            ]);
            return;
        }
        
        $deleted_count = 0;
        
        // Delete Child -> Parent relationship (stored on child user)
        $child_to_parent_id = get_user_meta($child_uid, 'lgl_family_relationship_id', true);
        
        if ($child_to_parent_id) {
            try {
                $response = $this->connection->deleteConstituentRelationship((int) $child_to_parent_id);
                
                if ($response['success']) {
                    delete_user_meta($child_uid, 'lgl_family_relationship_id');
                    $deleted_count++;
                    
                    $this->helper->debug('FamilyMemberDeactivationAction: Deleted Child->Parent relationship', [
                        'child_uid' => $child_uid,
                        'child_lgl_id' => $child_lgl_id,
                        'relationship_id' => $child_to_parent_id,
                        'direction' => 'Child->Parent'
                    ]);
                }
            } catch (\Exception $e) {
                $this->helper->debug('FamilyMemberDeactivationAction: Exception deleting Child->Parent relationship', [
                    'error' => $e->getMessage(),
                    'relationship_id' => $child_to_parent_id
                ]);
            }
        }
        
        // Delete Parent -> Child relationship (stored on child user for reference)
        $parent_to_child_id = get_user_meta($child_uid, 'lgl_family_relationship_parent_id', true);
        
        if ($parent_to_child_id) {
            try {
                $response = $this->connection->deleteConstituentRelationship((int) $parent_to_child_id);
                
                if ($response['success']) {
                    delete_user_meta($child_uid, 'lgl_family_relationship_parent_id');
                    $deleted_count++;
                    
                    $this->helper->debug('FamilyMemberDeactivationAction: Deleted Parent->Child relationship', [
                        'child_uid' => $child_uid,
                        'parent_lgl_id' => $parent_lgl_id,
                        'relationship_id' => $parent_to_child_id,
                        'direction' => 'Parent->Child'
                    ]);
                }
            } catch (\Exception $e) {
                $this->helper->debug('FamilyMemberDeactivationAction: Exception deleting Parent->Child relationship', [
                    'error' => $e->getMessage(),
                    'relationship_id' => $parent_to_child_id
                ]);
            }
        }
        
        // Fallback: Query API if stored IDs weren't found or deletion failed
        if ($deleted_count < 2) {
            // Query child's relationships to find any remaining Parent relationships
            try {
                $child_relationships = $this->connection->getConstituentRelationships((int) $child_lgl_id);
                
                if ($child_relationships['success'] && isset($child_relationships['data'])) {
                    $relationships = $this->extractRelationshipsFromResponse($child_relationships['data']);
                    
                    foreach ($relationships as $rel) {
                        $rel_id = is_object($rel) ? ($rel->id ?? null) : ($rel['id'] ?? null);
                        $rel_type = is_object($rel) ? 
                            ($rel->relationship_type_name ?? null) : 
                            ($rel['relationship_type_name'] ?? null);
                        
                        // Match Parent relationship (case-insensitive)
                        if ($rel_id && $rel_type && stripos($rel_type, 'Parent') !== false) {
                            $delete_response = $this->connection->deleteConstituentRelationship((int) $rel_id);
                            
                            if ($delete_response['success']) {
                                $deleted_count++;
                                $this->helper->debug('FamilyMemberDeactivationAction: Deleted Child->Parent relationship via API query', [
                                    'child_lgl_id' => $child_lgl_id,
                                    'relationship_id' => $rel_id,
                                    'relationship_type' => $rel_type
                                ]);
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->helper->debug('FamilyMemberDeactivationAction: Exception querying child relationships', [
                    'error' => $e->getMessage(),
                    'child_lgl_id' => $child_lgl_id
                ]);
            }
            
            // Query parent's relationships to find any remaining Child relationships
            if ($parent_lgl_id) {
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
                            
                            // Match Child relationship pointing to this child
                            if ($rel_id && $rel_type && stripos($rel_type, 'Child') !== false && 
                                $related_id && (int)$related_id === (int)$child_lgl_id) {
                                $delete_response = $this->connection->deleteConstituentRelationship((int) $rel_id);
                                
                                if ($delete_response['success']) {
                                    $deleted_count++;
                                    $this->helper->debug('FamilyMemberDeactivationAction: Deleted Parent->Child relationship via API query', [
                                        'parent_lgl_id' => $parent_lgl_id,
                                        'child_lgl_id' => $child_lgl_id,
                                        'relationship_id' => $rel_id,
                                        'relationship_type' => $rel_type
                                    ]);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->helper->debug('FamilyMemberDeactivationAction: Exception querying parent relationships', [
                        'error' => $e->getMessage(),
                        'parent_lgl_id' => $parent_lgl_id
                    ]);
                }
            }
        }
        
        $this->helper->debug('FamilyMemberDeactivationAction: Relationship deletion summary', [
            'child_uid' => $child_uid,
            'child_lgl_id' => $child_lgl_id,
            'parent_lgl_id' => $parent_lgl_id,
            'relationships_deleted' => $deleted_count,
            'expected' => 2
        ]);
    }
    
    /**
     * Extract relationships from LGL API response
     * 
     * Handles different response formats (items array, direct array, etc.)
     * 
     * @param mixed $data Response data
     * @return array Array of relationship objects/arrays
     */
    private function extractRelationshipsFromResponse($data): array {
        if (is_array($data)) {
            // Check if it has an 'items' key (LGL API format)
            if (isset($data['items']) && is_array($data['items'])) {
                return $data['items'];
            }
            // Direct array of relationships
            if (!empty($data) && (isset($data[0]['id']) || (is_object($data[0]) && isset($data[0]->id)))) {
                return $data;
            }
        } elseif (is_object($data)) {
            // Object with items property
            if (isset($data->items) && is_array($data->items)) {
                return $data->items;
            }
            // Single relationship object
            if (isset($data->id)) {
                return [$data];
            }
        }
        
        return [];
    }
    
    /**
     * Sync family slots after all removals
     * 
     * @param int $parent_id Parent user ID
     * @return void
     */
    private function syncFamilySlots(int $parent_id): void {
        $this->helper->syncUsedFamilySlotsMeta($parent_id);
        
        $total_purchased = (int) get_user_meta($parent_id, 'user_total_family_slots_purchased', true);
        $actual_used = $this->helper->getActualUsedFamilySlots($parent_id);
        $new_available = $total_purchased - $actual_used;
        update_user_meta($parent_id, 'user_available_family_slots', max(0, $new_available));
        
        $this->helper->debug('FamilyMemberDeactivationAction: Final family slots sync', [
            'parent_id' => $parent_id,
            'total_purchased' => $total_purchased,
            'actual_used' => $actual_used,
            'new_available' => $new_available
        ]);
    }
}

