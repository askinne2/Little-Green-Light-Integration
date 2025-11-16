# WordPress User Meta Fields - LGL Integration

This document describes custom WordPress user meta fields used by the Integrate-LGL plugin for managing family member relationships and LGL CRM synchronization.

## Family Member Relationship Fields

### `lgl_family_relationship_id`

**Purpose:** Stores the LGL constituent relationship ID for the Child -> Parent relationship.

**Created When:** A family member is added via `FamilyMemberAction` and the LGL constituent relationship is successfully created.

**Used When:** 
- Deleting a family member via `FamilyMemberDeactivationAction` to quickly identify and delete the Child->Parent relationship without API queries
- Cleaned up automatically when the relationship is deleted

**Format:** Integer (LGL relationship ID)

**Example:**
```php
// Get relationship ID
$relationship_id = get_user_meta($child_user_id, 'lgl_family_relationship_id', true);
// Returns: 12345 (or empty string if not set)

// Set relationship ID (done automatically by plugin)
update_user_meta($child_user_id, 'lgl_family_relationship_id', 12345);

// Delete relationship ID (done automatically when relationship is deleted)
delete_user_meta($child_user_id, 'lgl_family_relationship_id');
```

**Related Code:**
- **Created in:** `FamilyMemberAction::createLGLRelationship()`
- **Used in:** `FamilyMemberDeactivationAction::deleteLGLRelationship()`
- **LGL API Endpoint:** `POST /v1/constituents/{constituent_id}/constituent_relationships.json`
- **LGL Relationship Type:** `Parent` (from child's perspective)

**Notes:**
- This field stores the Child -> Parent relationship ID (created on the child constituent)
- This field is optional - if not present, the plugin will query the LGL API to find the relationship by type
- If relationship creation fails, this field will not be set, but family member creation will still succeed
- The field is automatically cleaned up when the relationship is deleted

### `lgl_family_relationship_parent_id`

**Purpose:** Stores the LGL constituent relationship ID for the Parent -> Child relationship (reciprocal).

**Created When:** A family member is added via `FamilyMemberAction` and the reciprocal Parent->Child relationship is successfully created.

**Used When:** 
- Deleting a family member via `FamilyMemberDeactivationAction` to quickly identify and delete the Parent->Child relationship without API queries
- Cleaned up automatically when the relationship is deleted

**Format:** Integer (LGL relationship ID)

**Example:**
```php
// Get reciprocal relationship ID
$parent_relationship_id = get_user_meta($child_user_id, 'lgl_family_relationship_parent_id', true);
// Returns: 12346 (or empty string if not set)

// Set relationship ID (done automatically by plugin)
update_user_meta($child_user_id, 'lgl_family_relationship_parent_id', 12346);

// Delete relationship ID (done automatically when relationship is deleted)
delete_user_meta($child_user_id, 'lgl_family_relationship_parent_id');
```

**Related Code:**
- **Created in:** `FamilyMemberAction::createLGLRelationship()`
- **Used in:** `FamilyMemberDeactivationAction::deleteLGLRelationship()`
- **LGL API Endpoint:** `POST /v1/constituents/{constituent_id}/constituent_relationships.json`
- **LGL Relationship Type:** `Child` (from parent's perspective)

**Notes:**
- This field stores the Parent -> Child relationship ID (created on the parent constituent, but stored on child user for reference)
- This field is optional - if not present, the plugin will query the LGL API to find the relationship by type
- Both directions of the relationship are created for complete bidirectional linking in LGL CRM
- If relationship creation fails, this field will not be set, but family member creation will still succeed
- The field is automatically cleaned up when the relationship is deleted

## Other LGL-Related User Meta Fields

### `lgl_id`
Stores the LGL constituent ID for the WordPress user. Used to link WordPress users to LGL CRM constituents.

### `user_total_family_slots_purchased`
Total number of family member slots purchased by the user.

### `user_used_family_slots`
Number of family member slots currently in use (synced from JetEngine relationships).

### `user_available_family_slots`
Number of available family member slots (calculated as: total_purchased - actual_used).

### `user-membership-type`
Stores the membership type/level for the user (e.g., "Individual", "Member", "Supporter", "Patron").

---

**Last Updated:** 2025-11-15  
**Plugin Version:** 2.0.0+

