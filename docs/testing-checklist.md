# LGL Integration Testing Checklist

## Automated Unit Tests
- `composer test` – runs PHPUnit suite (currently validates membership registration service happy-path).

## Manual End-to-End Scenarios
1. **JetForm Membership Signup**
   - Submit primary membership form for a new user.
   - Verify WooCommerce order completes, `_lgl_constituent_id` and `_lgl_membership_level_id` stored.
   - Confirm new constituent + membership + payment records in LGL.
2. **JetForm Family Member Add**
   - Add dependent using JetForm action.
   - Check `user-family-parent/children` meta and LGL relationship entry.
3. **Membership Renewal (JetForm)**
   - Trigger membership renewal action.
   - Ensure existing constituent updated with new membership period and payment.
4. **WooCommerce Membership Checkout**
   - Purchase membership product (online payment).
   - Confirm MembershipRegistrationService logs constituent + payment and order meta updated.
5. **Offline Payment Workflow**
   - Place membership order with check/cash.
   - Ensure cron (`lgl_manual_payment_queue`) processes payment and logs LGL gift.
6. **Class Registration Checkout**
   - Purchase language class product.
   - Verify JetEngine `class_registrations` entry, order meta, and LGL payment fund mapping.
7. **Event Registration Checkout**
   - Purchase event ticket with attendee details.
   - Confirm `_ui_event_registrations` CCT populated and payment recorded in LGL.
8. **Subscription Cancellation**
   - Cancel WooCommerce subscription.
   - Ensure user role downgraded and LGL membership deactivated.
9. **Daily Membership Cron**
   - Manually run `ui_memberships_daily_update` via WP-CLI.
   - Validate renewal reminders/log entries and membership status changes.
10. **Diagnostics Console Review**
    - Visit Tools → LGL Diagnostics.
    - Confirm cron hooks scheduled, JetForm/WooCommerce hooks registered, and logs readable.

## Regression Checks
- Confirm `[lgl]` and `[ui_memberships]` shortcodes render and respect legacy data.
- Verify Carbon Fields settings UI loads membership levels with imported schema data.
- Run `composer refresh` to regenerate optimized autoloader after changes to `src/` or `tests/`.
