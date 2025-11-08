# LGL Data Contracts Reference

## WordPress User Meta Keys
- `user-membership-type` – Canonical membership label (maps to LGL membership level).
- `user-membership-level` – Legacy display label (kept for backward compatibility with JetEngine listings).
- `user-membership-start-date` / `user-membership-renewal-date` – Unix timestamps for membership start and renewal dates.
- `user-membership-status` – Lifecycle status (`active`, `due_soon`, `overdue`, `expired`).
- `user-family-parent` / `user-family-children` – JSON encoded IDs linking household members.
- `payment-method` – `online`, `offline`, `stripe_cc`, `paypal`, etc. Used to map LGL payment type.
- `subscription-status` / `subscription-renewal-date` – WooCommerce Subscriptions mirror fields consumed by cron.
- `lgl_id` – Last known LGL constituent ID. Must remain in sync after registration/update.
- `lgl_membership_level_id` – New field storing the numeric LGL membership level ID selected via settings.
- `ui_membership_role_initialized` – Guards against duplicate role assignment during cron.

## WooCommerce Order Meta Keys
- `_lgl_constituent_id` – LGL constituent associated with the order.
- `_lgl_membership_level_id` – Membership level ID used when the order was processed.
- `_lgl_membership_level_name` – Original membership label at checkout time.
- `_ui_event_attendees` – JSON structure describing JetEngine attendee CCT references.
- `_ui_languages` / `_ui_country` / `_ui_referral` – Copied from JetForm submissions for analytics.
- `_lgl_payment_id` – Gift/payment ID returned by LGL after successful sync.
- `_manual_payment_queue` – Flag used by cron to defer processing for offline tenders.

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
- `lgl_register_user`
  - Required keys: `user_firstname`, `user_lastname`, `user_email`, `user_id`, `ui-membership-type`, `price`, `inserted_post_id`.
  - Optional: `payment_type`, `user_phone`, `user-address-1/2`, `user-country-of-origin`, `family_member_parent_id`.
- `lgl_add_family_member`
  - Mirrors registration payload but includes `parent_user_id` and `method = family_member`.
- `lgl_update_membership` / `lgl_renew_membership`
  - Require `user_id`, `ui-membership-type`, `membership_expiration`, `price`, `inserted_post_id`.
- `lgl_add_class_registration` / `lgl_add_event_registration`
  - Include `order_id`, `product_id`, attendee lists, fund override IDs, and any JetEngine repeater fields.

All actions expect sanitized arrays and will fail if the WooCommerce order or user meta is missing. Maintain identical key names to retain JetForm compatibility.

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
