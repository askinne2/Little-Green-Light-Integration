---
layout: default
title: User Meta Fields
---

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

## LGL Integration Fields

### `lgl_id`

**Purpose:** Primary LGL CRM constituent ID for the WordPress user.

**Created When:** User is synced to LGL CRM via `UserRegistrationAction`, `MembershipOrderHandler`, or other registration flows.

**Used When:**
- Looking up user's LGL constituent record
- Updating existing LGL constituent
- Creating gifts/payments in LGL
- Establishing constituent relationships

**Format:** Integer (LGL constituent ID)

**Example:**
```php
$lgl_id = get_user_meta($user_id, 'lgl_id', true);
// Returns: 12345 (or empty string if not synced to LGL)
```

**Related Code:**
- **Created in:** `WpUsers::registerUser()`, `Constituents::addConstituent()`, `MembershipRegistrationService::register()`
- **Used in:** All LGL API operations requiring constituent ID
- **Migration:** Legacy fields (`lgl_constituent_id`, `lgl_user_id`) are automatically migrated to `lgl_id` when accessed via `Constituents::getUserLglId()`. Use `WpUsers::migrateLglIdMetaFields()` for bulk migration.

### `lgl_membership_level_id`

**Purpose:** Stores the numeric LGL membership level ID for the user's current membership.

**Created When:** User purchases or updates membership via WooCommerce.

**Used When:**
- Creating LGL membership records
- Updating membership status
- Validating membership level

**Format:** Integer (LGL membership level ID from settings)

**Example:**
```php
$level_id = get_user_meta($user_id, 'lgl_membership_level_id', true);
// Returns: 123 (matches ID in LGL settings)
```

**Related Code:**
- **Created in:** `MembershipOrderHandler`, `MembershipUpdateAction`
- **Used in:** `WpUsers::addMembership()`

## Membership Status Fields

### `user-membership-type`

**Purpose:** Canonical membership label/type.

**Created When:** User purchases membership or membership is updated.

**Used When:**
- Displaying membership type in admin
- Determining access levels
- Mapping to LGL membership level

**Format:** String (e.g., "Individual", "Member", "Supporter", "Patron", "Household")

**Example:**
```php
$membership_type = get_user_meta($user_id, 'user-membership-type', true);
// Returns: "Supporter"
```

**Related Code:**
- **Created in:** `MembershipOrderHandler`, `UserRegistrationAction`
- **Used in:** Membership renewal logic, access control
- **LGL Mapping:** Maps to `lgl_membership_levels` in settings

### `user-membership-level`

**Purpose:** Legacy display label for membership (backward compatibility with JetEngine listings).

**Created When:** Membership is created or updated.

**Used When:**
- JetEngine queries for member listings
- Legacy shortcodes and templates
- Display purposes

**Format:** String (same as `user-membership-type` in most cases)

**Example:**
```php
$membership_level = get_user_meta($user_id, 'user-membership-level', true);
// Returns: "Supporter"
```

**Notes:**
- Maintained for backward compatibility
- Consider deprecating in favor of `user-membership-type`

### `user-membership-start-date`

**Purpose:** Date when the user's membership started.

**Created When:** Initial membership purchase or first-time registration.

**Used When:**
- Calculating membership duration
- Determining renewal eligibility
- Display in user profile

**Format:** Unix timestamp or date string (YYYY-MM-DD)

**Example:**
```php
$start_date = get_user_meta($user_id, 'user-membership-start-date', true);
$formatted = date('M j, Y', strtotime($start_date));
// Returns: "Jan 15, 2024"
```

**Related Code:**
- **Created in:** `UserRegistrationAction`, `MembershipOrderHandler`
- **Used in:** `MembershipRenewalManager`, `MembershipUserManager`

### `user-membership-renewal-date`

**Purpose:** Date when the membership expires and needs renewal.

**Created When:** Membership purchased or renewed.

**Used When:**
- Determining membership status (active, due_soon, overdue, expired)
- Sending renewal reminders
- Calculating grace period
- Automatic deactivation

**Format:** Unix timestamp or date string (YYYY-MM-DD)

**Example:**
```php
$renewal_date = get_user_meta($user_id, 'user-membership-renewal-date', true);
$days_until_renewal = (strtotime($renewal_date) - time()) / DAY_IN_SECONDS;
```

**Related Code:**
- **Created in:** `MembershipOrderHandler`, `MembershipRenewalAction`
- **Used in:** `MembershipRenewalManager::checkRenewalStatus()`
- **Reminder Intervals:** 30, 14, 7, 0, -7, -30 days

### `user-membership-status`

**Purpose:** Current lifecycle status of the membership.

**Created When:** Membership status changes (purchase, renewal, expiration).

**Used When:**
- Determining access levels
- Sending notifications
- Conditional UI display
- Role management

**Format:** String (enum)

**Possible Values:**
- `active` - Membership is current (renewal date in future)
- `due_soon` - Renewal due within 30 days
- `overdue` - Past renewal date but within 30-day grace period
- `expired` - More than 30 days past renewal date

**Example:**
```php
$status = get_user_meta($user_id, 'user-membership-status', true);
if ($status === 'expired') {
    // Restrict access or display renewal prompt
}
```

**Related Code:**
- **Created in:** `MembershipUserManager::updateMembershipStatus()`
- **Used in:** `MembershipCronManager` (daily checks)
- **Cron:** Updated daily via `ui_memberships_daily_update` hook

## Family Member Fields

### `user_total_family_slots_purchased`

**Purpose:** Total number of family member slots the user has purchased.

**Created When:** User purchases household/family membership.

**Used When:**
- Determining how many family members can be added
- Validating family member addition
- Displaying available slots

**Format:** Integer

**Example:**
```php
$total_slots = get_user_meta($user_id, 'user_total_family_slots_purchased', true);
// Returns: 4 (for a family of 4 membership)
```

**Related Code:**
- **Created in:** `MembershipOrderHandler` when processing household memberships
- **Used in:** `FamilyMemberAction::validateRequest()`

### `user_used_family_slots`

**Purpose:** Number of family member slots currently in use.

**Created When:** Synced from JetEngine relationships.

**Used When:**
- Calculating available slots
- Preventing over-allocation
- Display in user dashboard

**Format:** Integer

**Example:**
```php
$used_slots = get_user_meta($user_id, 'user_used_family_slots', true);
// Returns: 2 (user has 2 active family members)
```

**Related Code:**
- **Updated in:** `Helper::syncFamilySlots()` (triggered on relationship changes)
- **Synced from:** JetEngine `jet_rel_user_family_members` relationship

### `user_available_family_slots`

**Purpose:** Calculated number of available family member slots.

**Created When:** Calculated after `user_used_family_slots` is updated.

**Used When:**
- Displaying available slots in UI
- Validating family member addition
- Conditional form display

**Format:** Integer (calculated: `total_purchased - used_slots`)

**Example:**
```php
$available = get_user_meta($user_id, 'user_available_family_slots', true);
// Returns: 2 (can add 2 more family members)
```

**Calculation:**
```php
$available = $total_slots - $used_slots;
update_user_meta($user_id, 'user_available_family_slots', $available);
```

### `user-family-parent`

**Purpose:** JSON-encoded array of parent user IDs (for child/family member accounts).

**Created When:** User is added as a family member via `FamilyMemberAction`.

**Used When:**
- Identifying parent account
- Inheritance of membership benefits
- Household linking

**Format:** JSON string (array of user IDs)

**Example:**
```php
$parents_json = get_user_meta($user_id, 'user-family-parent', true);
$parents = json_decode($parents_json, true);
// Returns: [123] (parent user ID)
```

**Related Code:**
- **Created in:** `FamilyMemberAction`
- **Used in:** Membership inheritance logic

### `user-family-children`

**Purpose:** JSON-encoded array of child/family member user IDs.

**Created When:** Family members are added to the account.

**Used When:**
- Displaying family members
- Cascading membership actions
- Household management

**Format:** JSON string (array of user IDs)

**Example:**
```php
$children_json = get_user_meta($user_id, 'user-family-children', true);
$children = json_decode($children_json, true);
// Returns: [456, 789] (2 family member user IDs)
```

**Related Code:**
- **Created in:** `FamilyMemberAction`
- **Used in:** Family member management, cascade operations

## Payment & Subscription Fields

### `payment-method`

**Purpose:** Payment method used for the order/membership.

**Created When:** Order is processed in WooCommerce.

**Used When:**
- Mapping to LGL payment type
- Determining offline vs online payment
- Payment processing logic

**Format:** String

**Possible Values:**
- `online` - Generic online payment
- `offline` - Check, cash, or other offline payment
- `stripe_cc` - Stripe credit card
- `paypal` - PayPal payment
- `bacs` - Bank transfer
- `cheque` - Check payment
- `cod` - Cash on delivery

**Example:**
```php
$payment_method = get_user_meta($user_id, 'payment-method', true);
// Returns: "stripe_cc"
```

**Related Code:**
- **Created in:** `MembershipOrderHandler`, order processing
- **Used in:** `Payments::addGiftToConstituent()` for LGL payment type mapping

### `subscription-status`

**Purpose:** WooCommerce Subscriptions status mirror (for users with active subscriptions).

**Created When:** WooCommerce Subscription is created or updated.

**Used When:**
- Cron jobs for renewal processing
- Subscription status checks
- Automatic membership renewal

**Format:** String

**Possible Values:**
- `active` - Subscription is active and current
- `pending` - Awaiting payment
- `on-hold` - Payment failed, on hold
- `cancelled` - User cancelled subscription
- `expired` - Subscription expired
- `pending-cancel` - Scheduled for cancellation

**Example:**
```php
$sub_status = get_user_meta($user_id, 'subscription-status', true);
// Returns: "active"
```

**Related Code:**
- **Created in:** `SubscriptionHandler::updateUserSubscriptionStatus()`
- **Used in:** `MembershipCronManager` for renewal automation
- **WooCommerce Hook:** `woocommerce_subscription_status_updated`

### `subscription-renewal-date`

**Purpose:** Next scheduled renewal date for the subscription.

**Created When:** Subscription is created or renewed.

**Used When:**
- Automatic renewal processing
- Determining when to charge customer
- Syncing with membership renewal date

**Format:** Date string (YYYY-MM-DD) or Unix timestamp

**Example:**
```php
$renewal_date = get_user_meta($user_id, 'subscription-renewal-date', true);
// Returns: "2025-01-15"
```

**Related Code:**
- **Created in:** `SubscriptionHandler`
- **Synced with:** `user-membership-renewal-date`

## Billing & Contact Fields

### `user-phone`

**Purpose:** User's phone number.

**Created When:** Order is placed with phone number, or user updates profile.

**Used When:**
- Syncing to LGL constituent
- Contact information display
- Order notifications

**Format:** String (phone number, various formats)

**Example:**
```php
$phone = get_user_meta($user_id, 'user-phone', true);
// Returns: "(555) 123-4567"
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`
- **Synced to LGL:** `Constituents::addConstituent()` or `updateConstituent()`

### `user-company`

**Purpose:** Company/organization name.

**Created When:** Order billing information includes company.

**Format:** String

**Example:**
```php
$company = get_user_meta($user_id, 'user-company', true);
// Returns: "Acme Corporation"
```

### `user-address-1`

**Purpose:** Primary street address (line 1).

**Created When:** Order billing information is processed.

**Format:** String

**Example:**
```php
$address1 = get_user_meta($user_id, 'user-address-1', true);
// Returns: "123 Main St"
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`
- **Synced to LGL:** `Constituents` street address

### `user-address-2`

**Purpose:** Secondary street address (line 2 - apt, suite, etc.).

**Created When:** Order billing information includes address line 2.

**Format:** String

**Example:**
```php
$address2 = get_user_meta($user_id, 'user-address-2', true);
// Returns: "Apt 4B"
```

### `user-city`

**Purpose:** City name.

**Created When:** Order billing information is processed.

**Format:** String

**Example:**
```php
$city = get_user_meta($user_id, 'user-city', true);
// Returns: "New York"
```

### `user-state`

**Purpose:** State/province code.

**Created When:** Order billing information is processed.

**Format:** String (2-letter state code)

**Example:**
```php
$state = get_user_meta($user_id, 'user-state', true);
// Returns: "NY"
```

### `user-postal-code`

**Purpose:** ZIP/postal code.

**Created When:** Order billing information is processed.

**Format:** String

**Example:**
```php
$postal = get_user_meta($user_id, 'user-postal-code', true);
// Returns: "10001"
```

## Profile & Demographics Fields

### `user-languages`

**Purpose:** Languages spoken by the user (from membership registration form).

**Created When:** User registers or updates profile with language information.

**Used When:**
- Display in member directory
- Program matching/recommendations
- Analytics and reporting
- Copied to order meta for tracking

**Format:** String or JSON array (comma-separated or structured)

**Example:**
```php
$languages = get_user_meta($user_id, 'user-languages', true);
// Returns: "Spanish, French" or ["Spanish", "French"]
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`
- **Copied to order:** `_ui_languages` order meta

### `user-country-of-origin`

**Purpose:** User's country of origin (from membership registration form).

**Created When:** User registers with country information.

**Used When:**
- Demographics reporting
- Program targeting
- Community analytics
- Copied to order meta

**Format:** String (country name)

**Example:**
```php
$country = get_user_meta($user_id, 'user-country-of-origin', true);
// Returns: "Mexico"
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`
- **Copied to order:** `_ui_country` order meta

### `user-referral`

**Purpose:** How the user heard about the organization (referral source).

**Created When:** User completes membership registration form.

**Used When:**
- Marketing analytics
- Tracking effectiveness of outreach
- Member onboarding
- Copied to order meta

**Format:** String (free text or selected option)

**Example:**
```php
$referral = get_user_meta($user_id, 'user-referral', true);
// Returns: "Friend referral" or "Social media"
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`
- **Copied to order:** `_ui_referral` order meta

### `user-reason-for-membership`

**Purpose:** User's stated reason for joining.

**Created When:** User completes membership registration form.

**Used When:**
- Understanding member motivations
- Program development
- Member engagement strategies

**Format:** String (text)

**Example:**
```php
$reason = get_user_meta($user_id, 'user-reason-for-membership', true);
// Returns: "Want to practice Spanish and connect with community"
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`

### `about-me`

**Purpose:** User's self-description/bio.

**Created When:** User completes profile information.

**Used When:**
- Member directory listings
- Community connections
- Display in member profiles

**Format:** String (text, may contain HTML)

**Example:**
```php
$about = get_user_meta($user_id, 'about-me', true);
// Returns: "Retired teacher interested in Spanish conversation..."
```

**Related Code:**
- **Created in:** `WpUsers::updateUserMetaFromOrder()`

## System & Internal Fields

### `ui_membership_role_initialized`

**Purpose:** Flag to prevent duplicate role assignment during cron operations.

**Created When:** User's WordPress role is set to `ui_member` or `ui_patron_owner`.

**Used When:**
- Cron jobs check this to avoid redundant role updates
- Ensures role is only set once per membership lifecycle

**Format:** Boolean or timestamp

**Example:**
```php
$initialized = get_user_meta($user_id, 'ui_membership_role_initialized', true);
if (!$initialized) {
    // Set role for the first time
    $user->add_role('ui_member');
    update_user_meta($user_id, 'ui_membership_role_initialized', time());
}
```

**Related Code:**
- **Created in:** `MembershipUserManager::assignMembershipRole()`
- **Used in:** `MembershipCronManager` (guards against duplicate role assignment)

---

## Field Categories Summary

### Critical Fields (Required for core functionality)
- `lgl_id` - Primary LGL integration
- `user-membership-type` - Membership level
- `user-membership-renewal-date` - Expiration tracking
- `user-membership-status` - Lifecycle status

### Family Management
- `lgl_family_relationship_id` / `lgl_family_relationship_parent_id` - LGL relationships
- `user_total_family_slots_purchased` / `user_used_family_slots` / `user_available_family_slots` - Slot tracking
- `user-family-parent` / `user-family-children` - WordPress user linking

### Billing Information
- `user-phone`, `user-company`, `user-address-1/2`, `user-city`, `user-state`, `user-postal-code`

### Profile & Demographics
- `user-languages`, `user-country-of-origin`, `user-referral`, `user-reason-for-membership`, `about-me`

### Subscription Integration
- `subscription-status`, `subscription-renewal-date`, `payment-method`

### System/Internal
- `ui_membership_role_initialized`, `lgl_membership_level_id`

---

**Last Updated:** November 17, 2025  
**Plugin Version:** 2.0.0+  
**Total Fields Documented:** 35+

