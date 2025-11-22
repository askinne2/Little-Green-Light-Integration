<?php
/**
 * Group Membership Manager
 * 
 * Manages LGL group memberships for constituents.
 * Handles adding/removing constituents from groups.
 * 
 * @package UpstateInternational\LGL
 * @since 2.0.0
 */

namespace UpstateInternational\LGL\LGL;

use UpstateInternational\LGL\LGL\Connection;
use UpstateInternational\LGL\LGL\Helper;

class GroupMembershipManager {
    
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
     * Add constituent to LGL group
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $group_id LGL group ID
     * @return array API response
     */
    public function addGroupMembership(string $lgl_id, int $group_id): array {
        $endpoint = "constituents/{$lgl_id}/group_memberships";
        $payload = [
            'group_id' => $group_id
        ];
        
        $this->helper->debug('➕ GroupMembershipManager: Adding group membership', [
            'lgl_id' => $lgl_id,
            'group_id' => $group_id
        ]);
        
        $response = $this->connection->makeRequest($endpoint, 'POST', $payload, false);
        
        if ($response['success']) {
            $this->helper->debug('✅ GroupMembershipManager: Group membership added successfully', [
                'lgl_id' => $lgl_id,
                'group_id' => $group_id,
                'group_membership_id' => $response['data']['id'] ?? null
            ]);
        } else {
            $this->helper->debug('❌ GroupMembershipManager: Failed to add group membership', [
                'lgl_id' => $lgl_id,
                'group_id' => $group_id,
                'error' => $response['error'] ?? 'Unknown error',
                'http_code' => $response['http_code'] ?? null
            ]);
        }
        
        return $response;
    }
    
    /**
     * Remove constituent from LGL group
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $group_membership_id Group membership ID (not group ID)
     * @return array API response
     */
    public function removeGroupMembership(string $lgl_id, int $group_membership_id): array {
        $endpoint = "group_memberships/{$group_membership_id}";
        
        $this->helper->debug('➖ GroupMembershipManager: Removing group membership', [
            'lgl_id' => $lgl_id,
            'group_membership_id' => $group_membership_id
        ]);
        
        $response = $this->connection->makeRequest($endpoint, 'DELETE', null, false);
        
        if ($response['success']) {
            $this->helper->debug('✅ GroupMembershipManager: Group membership removed successfully', [
                'lgl_id' => $lgl_id,
                'group_membership_id' => $group_membership_id
            ]);
        } else {
            $this->helper->debug('❌ GroupMembershipManager: Failed to remove group membership', [
                'lgl_id' => $lgl_id,
                'group_membership_id' => $group_membership_id,
                'error' => $response['error'] ?? 'Unknown error',
                'http_code' => $response['http_code'] ?? null
            ]);
        }
        
        return $response;
    }
    
    /**
     * Get all group memberships for a constituent
     * 
     * @param string $lgl_id LGL constituent ID
     * @param bool $use_cache Whether to use cached data (default: true)
     * @return array List of group memberships
     */
    public function getGroupMemberships(string $lgl_id, bool $use_cache = true): array {
        $endpoint = "constituents/{$lgl_id}/group_memberships";
        $response = $this->connection->makeRequest($endpoint, 'GET', [], $use_cache);
        
        if ($response['success'] && isset($response['data']['items'])) {
            return $response['data']['items'];
        }
        
        return [];
    }
    
    /**
     * Check if constituent is in a specific group
     * 
     * IMPORTANT: This bypasses cache to ensure we check for the most recent group memberships.
     * This prevents duplicate group additions when groups are added in quick succession.
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $group_id LGL group ID
     * @return bool True if constituent is in group
     */
    public function isInGroup(string $lgl_id, int $group_id): bool {
        // Bypass cache to get fresh data - prevents duplicate group additions
        $memberships = $this->getGroupMemberships($lgl_id, false);
        
        $this->helper->debug('🔍 GroupMembershipManager: Checking if in group', [
            'lgl_id' => $lgl_id,
            'group_id' => $group_id,
            'memberships_count' => count($memberships),
            'membership_group_ids' => array_map(function($m) { return $m['group_id'] ?? null; }, $memberships)
        ]);
        
        foreach ($memberships as $membership) {
            if (isset($membership['group_id']) && (int)$membership['group_id'] === $group_id) {
                $this->helper->debug('✅ GroupMembershipManager: Found existing group membership', [
                    'lgl_id' => $lgl_id,
                    'group_id' => $group_id,
                    'membership_id' => $membership['id'] ?? null
                ]);
                return true;
            }
        }
        
        $this->helper->debug('ℹ️ GroupMembershipManager: Not in group', [
            'lgl_id' => $lgl_id,
            'group_id' => $group_id
        ]);
        
        return false;
    }
    
    /**
     * Remove constituent from group by group ID (finds membership ID first)
     * 
     * @param string $lgl_id LGL constituent ID
     * @param int $group_id LGL group ID
     * @return array API response
     */
    public function removeGroupMembershipByGroupId(string $lgl_id, int $group_id): array {
        $memberships = $this->getGroupMemberships($lgl_id);
        
        foreach ($memberships as $membership) {
            if (isset($membership['group_id']) && (int)$membership['group_id'] === $group_id) {
                $membership_id = $membership['id'] ?? null;
                if ($membership_id) {
                    return $this->removeGroupMembership($lgl_id, (int)$membership_id);
                }
            }
        }
        
        $this->helper->debug('⚠️ GroupMembershipManager: Group membership not found', [
            'lgl_id' => $lgl_id,
            'group_id' => $group_id
        ]);
        
        return [
            'success' => false,
            'error' => 'Group membership not found'
        ];
    }
}

