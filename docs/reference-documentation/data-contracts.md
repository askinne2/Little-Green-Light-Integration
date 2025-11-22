---
layout: default
title: Data Contracts
---

# LGL Data Contracts Reference

## WordPress User Meta Keys
- `user-membership-type` – Canonical membership label (maps to LGL membership level).
- `user-membership-level` – Legacy display label (kept for backward compatibility with JetEngine listings).
- `user-membership-start-date` / `user-membership-renewal-date` – Unix timestamps for membership start and renewal dates.
- `user-membership-status` – Lifecycle status (`active`, `due_soon`, `overdue`, `expired`).
- `user-family-parent` / `user-family-children` – JSON encoded IDs linking household members.
- `payment-method` – `online`, `offline`, `stripe_cc`, `paypal`, etc. Used to map LGL payment type.
- `subscription-status` / `subscription-renewal-date` – WooCommerce Subscriptions mirror fields consumed by cron.
- `lgl_id` – **Canonical field** for LGL constituent ID. Must remain in sync after registration/update. Legacy fields (`lgl_constituent_id`, `lgl_user_id`) are automatically migrated to this field.
- `lgl_membership_level_id` – New field storing the numeric LGL membership level ID selected via settings.
- `ui_membership_role_initialized` – Guards against duplicate role assignment during cron.

## WooCommerce Order Meta Keys

### Core LGL Integration Fields

#### `_lgl_constituent_id`
**Purpose:** LGL constituent ID associated with the order customer.

**Set When:** Order is processed and synced to LGL CRM.

**Used When:**
- Creating gifts/payments in LGL
- Linking order to constituent record
- Verifying LGL sync status

**Format:** Integer (LGL constituent ID)

**Set By:** `MembershipOrderHandler`, `ClassOrderHandler`, `EventOrderHandler`

#### `_lgl_payment_id`
**Purpose:** Gift/payment ID returned by LGL after successful payment sync.

**Set When:** Payment is successfully created in LGL CRM via `Payments::addGiftToConstituent()`.

**Used When:**
- Verifying payment sync status
- Preventing duplicate payment creation
- Audit trails and reporting

**Format:** Integer (LGL gift ID)

**Set By:** `Payments::addGiftToConstituent()`

#### `_lgl_membership_level_id`
**Purpose:** Numeric LGL membership level ID used when the order was processed.

**Set When:** Membership order is processed.

**Used When:**
- Creating membership records in LGL
- Mapping to specific LGL membership level
- Historical tracking

**Format:** Integer (matches LGL membership level settings)

**Set By:** `MembershipOrderHandler`

#### `_lgl_membership_level_name`
**Purpose:** Human-readable membership label at checkout time.

**Set When:** Membership order is processed.

**Used When:**
- Display in order details
- Email templates
- Historical reference (even if settings change)

**Format:** String (e.g., "Supporter", "Patron")

**Set By:** `MembershipOrderHandler`

### Payment Processing Fields

#### `_manual_payment_queue`
**Purpose:** Flag to indicate order needs manual/offline payment processing via cron.

**Set When:** Order uses offline payment method (check, cash, etc.).

**Used When:**
- Cron job `lgl_manual_payment_queue` processes deferred payments
- Prevents immediate LGL payment creation for offline orders

**Format:** Boolean (1 or true)

**Set By:** `CheckOrderHandler::lglProcessCheckOrders()`

**Cleared By:** Cron job after successful processing

#### `_payment_method`
**Purpose:** WooCommerce payment gateway used for the order.

**Set When:** Order is placed (WooCommerce core).

**Used When:**
- Mapping to LGL payment type
- Determining online vs offline processing
- Payment reconciliation

**Format:** String (gateway ID)

**Possible Values:** `stripe`, `paypal`, `bacs`, `cheque`, `cod`, etc.

### User Demographics & Registration Fields

#### `_ui_languages`
**Purpose:** Languages spoken by the customer (from checkout form).

**Set When:** Membership registration form is submitted.

**Used When:**
- Analytics and demographics reporting
- Member directory display
- Program recommendations

**Format:** String (comma-separated) or JSON array

**Set By:** `WpUsers::updateUserMetaFromOrder()`

**Also Stored:** `user-languages` user meta

#### `_ui_country`
**Purpose:** Customer's country of origin (from checkout form).

**Set When:** Membership registration form is submitted.

**Used When:**
- Demographics reporting
- Community analytics
- Display in member profile

**Format:** String (country name)

**Set By:** `WpUsers::updateUserMetaFromOrder()`

**Also Stored:** `user-country-of-origin` user meta

#### `_ui_referral`
**Purpose:** How the customer heard about the organization.

**Set When:** Membership registration form is submitted.

**Used When:**
- Marketing effectiveness tracking
- Referral source analytics
- Member onboarding

**Format:** String (referral source)

**Set By:** `WpUsers::updateUserMetaFromOrder()`

**Also Stored:** `user-referral` user meta

#### `_order_reason_for_membership`
**Purpose:** Customer's stated reason for joining (optional field).

**Set When:** Membership registration form includes reason.

**Used When:**
- Understanding member motivations
- Program development insights

**Format:** String (text)

#### `_order_tell_us_about_yourself`
**Purpose:** Customer's self-description (optional field).

**Set When:** Membership registration form includes about field.

**Used When:**
- Member directory
- Community connections

**Format:** String (text)

### Event Registration Fields

#### `_ui_event_attendees`
**Purpose:** JSON structure describing event attendees and JetEngine CCT references.

**Set When:** Event registration order is processed.

**Used When:**
- Creating event registration records in JetEngine CCT
- Tracking multiple attendees per order
- Syncing attendee info to LGL

**Format:** JSON array

**Structure:**
```json
[
  {
    "attendee_name": "John Doe",
    "attendee_email": "john@example.com",
    "meal_preference": "vegetarian",
    "cct_id": 123,
    "lgl_synced": true
  }
]
```

**Set By:** `EventOrderHandler::doWooCommerceLGLEventRegistration()`

**Related CCT:** `_ui_event_registrations`

#### `_event_product_id`
**Purpose:** Event product/variation ID.

**Set When:** Event order contains specific event product.

**Used When:**
- Identifying which event was purchased
- Linking order to event post

**Format:** Integer (product ID)

#### `_event_date`
**Purpose:** Date of the event.

**Set When:** Event registration is processed (if available).

**Used When:**
- Display in order details
- Event attendance tracking

**Format:** Date string (YYYY-MM-DD)

#### `_event_attendee_count`
**Purpose:** Number of attendees for this event order.

**Set When:** Event registration is processed.

**Used When:**
- Capacity management
- Reporting

**Format:** Integer

### Class Registration Fields

#### `_class_product_id`
**Purpose:** Language class product ID.

**Set When:** Class registration order is processed.

**Used When:**
- Identifying which class was purchased
- Linking order to class/course

**Format:** Integer (product ID)

**Set By:** `ClassOrderHandler`

#### `_class_registration_cct_id`
**Purpose:** JetEngine CCT record ID for the class registration.

**Set When:** Class registration is created in `jet_cct_class_registrations` CCT.

**Used When:**
- Linking order to class registration record
- Updating registration status
- Attendance tracking

**Format:** Integer (CCT record ID)

**Set By:** `ClassOrderHandler::doWooCommerceLGLClassRegistration()`

#### `_class_attendee_name`
**Purpose:** Name of the class attendee (may differ from order customer).

**Set When:** Class registration form is submitted.

**Used When:**
- Display in class rosters
- Attendance tracking
- Certificates/completion

**Format:** String

#### `_class_session_id`
**Purpose:** Specific class session or term ID (if applicable).

**Set When:** Multi-session class registration.

**Used When:**
- Tracking which session/term
- Scheduling
- Reporting

**Format:** Integer or String

### LGL Fund & Campaign Overrides

#### `_lgl_fund_id`
**Purpose:** Override LGL fund ID for this specific order (if not using default).

**Set When:** Product has custom fund mapping or admin override.

**Used When:**
- Creating gift in LGL with specific fund
- Overriding default category fund mapping

**Format:** Integer (LGL fund ID)

**Set By:** Product meta or order processing logic

#### `_lgl_campaign_id`
**Purpose:** Override LGL campaign ID for this specific order.

**Set When:** Product has custom campaign mapping.

**Used When:**
- Creating gift in LGL with specific campaign
- Campaign-specific tracking

**Format:** Integer (LGL campaign ID)

**Set By:** Product meta or order processing logic

#### `_lgl_gift_category_id`
**Purpose:** LGL gift category ID for the order.

**Set When:** Order is categorized for LGL sync.

**Used When:**
- Creating gift with proper category in LGL
- Financial reporting

**Format:** Integer (LGL gift category ID)

### Order Processing Status Fields

#### `_lgl_sync_status`
**Purpose:** Status of LGL synchronization for this order.

**Set When:** Order sync is attempted.

**Used When:**
- Tracking sync success/failure
- Retry logic for failed syncs
- Admin reporting

**Format:** String

**Possible Values:**
- `pending` - Not yet synced
- `syncing` - Sync in progress
- `synced` - Successfully synced
- `failed` - Sync failed
- `skipped` - Intentionally not synced

#### `_lgl_sync_date`
**Purpose:** Timestamp when order was successfully synced to LGL.

**Set When:** LGL sync completes successfully.

**Used When:**
- Audit trails
- Sync reporting
- Troubleshooting

**Format:** Unix timestamp or datetime string

#### `_lgl_sync_error`
**Purpose:** Error message if LGL sync failed.

**Set When:** LGL sync encounters an error.

**Used When:**
- Troubleshooting sync failures
- Admin notifications
- Retry logic

**Format:** String (error message)

### Family Member Orders

#### `_family_member_parent_id`
**Purpose:** Parent user ID if order is for adding a family member.

**Set When:** Family member is added via checkout.

**Used When:**
- Linking child to parent account
- Inheritance of membership benefits
- Family slot management

**Format:** Integer (WordPress user ID)

**Set By:** `FamilyMemberAction`

#### `_family_member_slots_used`
**Purpose:** Number of family member slots consumed by this order.

**Set When:** Household/family membership is purchased.

**Used When:**
- Tracking slot allocation
- Preventing over-allocation

**Format:** Integer

### WooCommerce Subscription Integration

#### `_subscription_id`
**Purpose:** WooCommerce Subscription ID linked to this order.

**Set When:** Order creates or renews a subscription.

**Used When:**
- Linking order to subscription
- Automatic renewal processing
- Subscription management

**Format:** Integer (subscription post ID)

**Set By:** WooCommerce Subscriptions plugin

#### `_subscription_renewal`
**Purpose:** Flag indicating this is a subscription renewal order.

**Set When:** Subscription automatically renews.

**Used When:**
- Distinguishing initial vs renewal orders
- Renewal-specific processing

**Format:** Boolean

### Custom Checkout Fields

#### `_order_languages_spoken`
**Purpose:** Duplicate/alias of `_ui_languages` for some forms.

**Format:** String

#### `_order_country_of_origin`
**Purpose:** Duplicate/alias of `_ui_country` for some forms.

**Format:** String

#### `_order_referral_source`
**Purpose:** Duplicate/alias of `_ui_referral` for some forms.

**Format:** String

---

### Order Meta Summary by Product Type

#### Membership Orders
- Core: `_lgl_constituent_id`, `_lgl_payment_id`, `_lgl_membership_level_id`, `_lgl_membership_level_name`
- Demographics: `_ui_languages`, `_ui_country`, `_ui_referral`
- Subscription: `_subscription_id`, `_subscription_renewal`

#### Event Registration Orders
- Core: `_lgl_constituent_id`, `_lgl_payment_id`
- Event: `_ui_event_attendees`, `_event_product_id`, `_event_date`, `_event_attendee_count`
- Fund: `_lgl_fund_id`, `_lgl_campaign_id`

#### Class Registration Orders
- Core: `_lgl_constituent_id`, `_lgl_payment_id`
- Class: `_class_product_id`, `_class_registration_cct_id`, `_class_attendee_name`, `_class_session_id`
- Fund: `_lgl_fund_id`, `_lgl_campaign_id`

#### Family Member Orders
- Core: `_lgl_constituent_id`, `_lgl_payment_id`
- Family: `_family_member_parent_id`, `_family_member_slots_used`

## JetEngine CCT Schemas
- `class_registrations` – Stores class enrolments. Required columns: `jet_cct_class_registrations` slug, attendee names, order ID, membership level, payment status.
- `_ui_event_registrations` – Event attendees with fields: event post ID, order ID, attendee info, meal preference, and LGL sync flag.
- Relationships:
  - `jet_rel_user_family_members` – Parent ↔ child WordPress user IDs.
  - `jet_rel_order_class_registration` – WooCommerce order ↔ class registration CCT.
  - `jet_rel_order_event_registration` – WooCommerce order ↔ event registration CCT.
  - `jet_rel_family_primary_member` – JetEngine relation linking household primary to dependents.
- Relation endpoint IDs are stored in `LGL_Relations_Manager` and must match live JetEngine REST endpoints.

## JetForm Builder Action Payloads

### ⚠️ Deprecated Actions (v2.0+)
These actions are maintained for backward compatibility only. **New implementations should use WooCommerce checkout instead.**

- `lgl_register_user` **@deprecated** - Use WooCommerce membership purchase instead
  - Required keys: `user_firstname`, `user_lastname`, `user_email`, `user_id`, `ui-membership-type`, `price`, `inserted_post_id`.
  - Optional: `payment_type`, `user_phone`, `user-address-1/2`, `user-country-of-origin`, `family_member_parent_id`.
  
- `lgl_update_membership` **@deprecated** - Use WooCommerce checkout for tier changes instead
  - Require `user_id`, `ui-membership-type`, `membership_expiration`, `price`, `inserted_post_id`.
  
- `lgl_add_class_registration` **@deprecated** - CourseStorm handles new language class registrations externally
  - Include `order_id`, `product_id`, attendee lists, fund override IDs, and any JetEngine repeater fields.

### ✅ Active Actions (Current Use)
These actions are actively used for member management tasks that don't require payment processing.

- `lgl_renew_membership` **✅ Active**
  - Require `user_id`, `ui-membership-type`, `membership_expiration`, `price`, `inserted_post_id`.
  - Used for membership renewals without payment (e.g., staff-processed renewals)
  
- `lgl_add_family_member` **✅ Active**
  - Mirrors registration payload but includes `parent_user_id` and `method = family_member`.
  - Used to add family members to existing household memberships
  
- `lgl_add_event_registration` **✅ Active**
  - Include `order_id`, `product_id`, attendee lists, fund override IDs, and any JetEngine repeater fields.
  - Used for event registrations via forms
  
- `lgl_edit_user` **✅ Active**
  - Used for profile updates and member information changes
  
- `lgl_deactivate_membership` **✅ Active**
  - Used for membership cancellation/deactivation requests
  
- `lgl_deactivate_family_member` **✅ Active**
  - Used to remove family members from household memberships

**Note:** All actions expect sanitized arrays and will fail if the WooCommerce order or user meta is missing. Maintain identical key names to retain JetForm compatibility.

## WooCommerce Category → LGL Mapping
- `memberships`
  - Maps to membership level IDs via `ApiSettings`. Determines role escalation, membership creation, and default fund/campaign (`Membership Fees`, `General Fund`).
- `language-class`
  - Routes to class registration handler. Default fund/campaign sourced from settings (`Language Classes` fund, `Programs` campaign).
- `events`
  - Routes to event registration handler. Default fund/campaign stored in settings (`Events` fund) with optional per-product overrides.

Category detection controls which handler executes and which JetEngine CCT is updated. Ensure slugs remain stable.

## Cron & Scheduler Hooks
- `ui_memberships_daily_update` (`UI_MEMBERSHIPS_CRON_HOOK`)
  - Daily renewal checks, reminder emails, status updates, payment cache refresh.
- `ui_memberships_delete_inactive` (`UI_DELETE_MEMBERS`)
  - Monthly cleanup removing accounts flagged as inactive beyond retention window.
- `lgl_manual_payment_queue`
  - Processes deferred offline payments created during WooCommerce checkout.
- WooCommerce subscription hooks
  - `woocommerce_subscription_status_cancelled`
  - `woocommerce_subscription_status_updated`
- All hooks must remain registered on activation and cleared on deactivation.

## Settings & External Configuration
- Carbon Fields / SettingsManager options (mirrored through modern SettingsHandler):
  - `lgl_api_url`, `lgl_api_key`, `lgl_membership_levels`, `lgl_fund_mappings`, `lgl_campaign_mappings`, `lgl_payment_types`.
  - Import/export uses JSON schema matching files in `docs/lgl-exports/`.
- Debug constants
  - `HELPER_PLUGIN_DEBUG`, `PLUGIN_DEBUG`, `REMOVE_TRANSIENT`, `LGL_DATA_DELAY`, `PAYMENT_TIME_WINDOW`.
  - Ensure any overrides are surfaced through Advanced settings tab.

## Logging & Audit Trails
- PHP error log (`debug.log`) receives entries prefixed `LGL` when debug constants enabled.
- Future `LGL Event Log` should aggregate: timestamp, endpoint, payload hash, result (`success`, `error`, `http_code`), associated user/order IDs.
- Order meta + JetEngine CCT fields listed above must be populated to maintain downstream reporting.
