---
layout: default
title: Manual Testing Guide
---

# Manual Testing Guide

This guide provides comprehensive manual testing procedures for the Integrate-LGL plugin before production deployment.

## Pre-Deployment Checklist

### Environment Verification

- [x] WordPress version 5.0+ confirmed
- [x] PHP version 7.4+ confirmed (8.0+ recommended)
- [x] WooCommerce plugin active and configured
- [x] JetFormBuilder plugin active (if using forms)
- [-] SSL/HTTPS enabled
- [x] Backup created before testing
- [x] Test environment mirrors production as closely as possible

### Plugin Configuration

- [x] LGL API URL configured correctly
- [x] LGL API key valid and tested
- [x] Membership levels imported and mapped
- [x] Funds mapped correctly
- [x] Campaigns mapped correctly
- [x] Payment types mapped correctly
- [ ] Debug mode OFF for production
- [ ] Email blocking disabled (or configured correctly)

---

## Critical User Flows

### 1. Membership Registration (WooCommerce)

**Priority:** 🔴 CRITICAL

**Test Steps:**
1. Navigate to membership products page
2. Select "Supporter" membership product
3. Add to cart
4. Proceed to checkout
5. Fill in billing information:
   - First Name: Test
   - Last Name: User
   - Email: testuser+DATE@example.com (use unique email)
   - Phone: (555) 123-4567
   - Address: Complete address
6. Complete checkout with test payment method
7. Confirm order completion

**Expected Results:**
- ✅ Order status: Completed
- ✅ User account created
- ✅ User assigned `ui_member` role
- ✅ User meta fields populated:
  - `lgl_id` (constituent ID)
  - `user-membership-type` = "Supporter"
  - `user-membership-start-date` = today
  - `user-membership-renewal-date` = +1 year
  - `user-membership-status` = "active"
- ✅ Order meta fields populated:
  - `_lgl_constituent_id`
  - `_lgl_payment_id`
  - `_lgl_membership_level_id`
- ✅ LGL CRM updated:
  - Constituent created
  - Membership record created
  - Gift/payment recorded
- ✅ Confirmation email sent

**How to Verify in LGL:**
1. Log into LGL CRM
2. Search for constituent by email
3. Verify constituent record exists
4. Check Memberships tab
5. Check Gifts tab for payment

**Common Issues:**
- Missing LGL ID: Check debug log for API errors
- No membership created: Verify membership level mapping
- Payment not recorded: Check fund/campaign mapping

---

### 2. Family Member Addition

**Priority:** 🔴 CRITICAL

**Prerequisites:**
- Primary member account with family slots available
- Household membership purchased

**Test Steps:**
1. Log in as primary member
2. Navigate to family member form
3. Fill in family member details:
   - First Name: Child
   - Last Name: User
   - Email: child+DATE@example.com
   - Relationship: Child
4. Submit form

**Expected Results:**
- ✅ Family member WordPress user created
- ✅ Family member linked to parent in WordPress
- ✅ LGL constituent created for family member
- ✅ LGL constituent relationship created (bidirectional)
- ✅ Family slots decremented
- ✅ User meta updated:
  - Parent: `user-family-children` includes new user
  - Child: `user-family-parent` = parent user ID
  - Child: `lgl_family_relationship_id` (relationship ID)

**How to Verify:**
1. Check parent user meta: `user_used_family_slots`
2. Check LGL relationship in parent constituent record
3. Check LGL relationship in child constituent record

---

### 3. Membership Renewal

**Priority:** 🔴 CRITICAL

**Test Steps:**
1. Create test membership with renewal date = today
2. Purchase renewal product via WooCommerce
3. Complete checkout

**Expected Results:**
- ✅ Membership renewal date extended +1 year
- ✅ Membership status remains "active"
- ✅ New gift/payment recorded in LGL
- ✅ Order meta populated
- ✅ Renewal confirmation email sent

**Automated Renewal Test:**
1. Create test user with renewal date 7 days ago
2. Run cron: `wp cron event run ui_memberships_daily_update`
3. Verify renewal reminder email sent
4. Check user status updated to "overdue"

---

### 4. Event Registration

**Priority:** 🟡 HIGH

**Test Steps:**
1. Navigate to event product page
2. Add event ticket to cart
3. Fill in attendee information:
   - Attendee Name
   - Attendee Email
   - Meal Preference (if applicable)
4. Complete checkout

**Expected Results:**
- ✅ Order completed
- ✅ Event registration CCT record created
- ✅ Attendee information saved
- ✅ Order meta `_ui_event_attendees` populated
- ✅ LGL gift created with event fund
- ✅ Registration confirmation email sent

**How to Verify:**
1. Check JetEngine CCT: `_ui_event_registrations`
2. Query by order ID
3. Verify attendee details match

---

### 5. Class Registration

**Priority:** 🟡 HIGH

**Test Steps:**
1. Navigate to language class product
2. Add class to cart
3. Fill in attendee information
4. Complete checkout

**Expected Results:**
- ✅ Order completed
- ✅ Class registration CCT record created
- ✅ Order meta `_class_registration_cct_id` populated
- ✅ LGL gift created with class fund
- ✅ Registration confirmation email sent

**How to Verify:**
1. Check JetEngine CCT: `class_registrations`
2. Query by order ID
3. Verify registration details

---

### 6. Email Delivery

**Priority:** 🟡 HIGH

**Email Types to Test:**
- Membership confirmation
- Membership renewal reminder (7 days, 30 days before)
- Membership overdue notice
- Event registration confirmation
- Class registration confirmation
- Family member welcome
- Order completion (custom content)

**Test Steps:**
1. Configure test email address in whitelist (if blocking enabled)
2. Trigger each email type
3. Verify receipt and content

**Verify:**
- ✅ Emails delivered
- ✅ Content accurate and personalized
- ✅ Links work correctly
- ✅ Styling renders properly
- ✅ No blocked emails in production

---

## Admin Interface Testing

### Settings Page

**Test Areas:**
- [ ] API connection test succeeds
- [ ] Membership levels import works
- [ ] Funds import works
- [ ] Campaigns import works
- [ ] Settings save correctly
- [ ] Settings load on page refresh
- [ ] Connection test with invalid credentials fails gracefully
- [ ] Import with no API key shows error

### Dashboard

**Test Areas:**
- [ ] Statistics display correctly
- [ ] Recent orders show
- [ ] Cache statistics accurate
- [ ] Rate limiter status displays
- [ ] No PHP errors in browser console

### Testing Utilities

**Test Areas:**
- [ ] Test constituent creation works
- [ ] Test gift creation works
- [ ] Test membership creation works
- [ ] Test connection displays API response
- [ ] Error messages are clear
- [ ] Test data cleanup works

---

## Edge Cases & Error Handling

### API Failures

**Test Scenarios:**
1. **Invalid API Key**
   - Set invalid API key
   - Attempt membership registration
   - Expected: Error message, order processing continues, retry later

2. **API Rate Limit**
   - Trigger 300+ requests in 5 minutes (use testing script)
   - Expected: Automatic delay, waiting message in logs

3. **Network Timeout**
   - Simulate slow network
   - Expected: Timeout after 10 seconds, error logged

4. **Invalid Data**
   - Submit form with missing required fields
   - Expected: Validation error before API call

### Duplicate Prevention

**Test Scenarios:**
1. **Duplicate Email**
   - Register with existing email
   - Expected: Find existing constituent, don't create duplicate

2. **Duplicate Payment**
   - Process same order twice
   - Expected: Check for existing `_lgl_payment_id`, skip if exists

3. **Duplicate Membership**
   - Renew before expiration
   - Expected: Extend existing membership, don't create new

### Data Integrity

**Test Scenarios:**
1. **Orphaned Family Members**
   - Delete parent user
   - Expected: Family member remains, LGL relationship intact

2. **Failed LGL Sync**
   - Create order with API down
   - Expected: Order completes, sync queued for retry

3. **Partial Data**
   - Submit form with some fields empty
   - Expected: Required fields enforced, optional fields handled

---

## Performance Testing

### Load Testing

**Test Scenarios:**
1. **Concurrent Registrations**
   - 5-10 simultaneous membership registrations
   - Expected: All process successfully, rate limiting prevents API errors

2. **Bulk User Sync**
   - Sync 100 users via testing utility
   - Expected: Completes within reasonable time, no timeouts

3. **Dashboard Load**
   - Access dashboard with 1000+ members
   - Expected: Loads within 2-3 seconds (with caching)

### Cache Testing

**Test Scenarios:**
1. **Cache Hit**
   - Load settings page twice
   - Expected: Second load faster, cache hit in logs

2. **Cache Invalidation**
   - Update settings
   - Expected: Cache cleared, new values loaded

3. **Cache Expiration**
   - Wait for cache TTL to expire
   - Expected: Fresh data loaded

---

## Security Testing

### Authentication

**Test Scenarios:**
- [ ] Admin pages require login
- [ ] Settings page requires `manage_options` capability
- [ ] AJAX endpoints verify nonces
- [ ] Test with non-admin user (should be blocked)
- [ ] Test with logged-out user (should redirect to login)

### Input Validation

**Test Scenarios:**
- [ ] Submit form with SQL injection attempt (should be sanitized)
- [ ] Submit form with XSS attempt (should be escaped)
- [ ] Submit form with invalid email (should be rejected)
- [ ] Submit form with script tags (should be stripped)

### File Access

**Test Scenarios:**
- [ ] Try accessing plugin files directly in browser
- [ ] Expected: 403 Forbidden or ABSPATH check

---

## Browser Compatibility

**Test in:**
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile Safari (iOS)
- [ ] Chrome Mobile (Android)

**Test:**
- [ ] Admin interface displays correctly
- [ ] Forms submit successfully
- [ ] AJAX requests work
- [ ] JavaScript console shows no errors

---

## Final Pre-Production Checklist

### Code Review
- [ ] No debug code left in production files
- [ ] No commented-out code blocks
- [ ] No TODO or FIXME comments for critical items
- [ ] All console.log() statements removed

### Configuration
- [ ] Debug mode: OFF
- [ ] Test mode: OFF
- [ ] API credentials: PRODUCTION values
- [ ] Email blocking: DISABLED (or configured for production)
- [ ] HTTPS: ENABLED
- [ ] WordPress DEBUG: OFF
- [ ] PHP display_errors: OFF

### Database
- [ ] Backup created
- [ ] Backup tested (restore verification)
- [ ] Old test data cleaned up
- [ ] Transients cleared

### Documentation
- [ ] Admin users trained
- [ ] Troubleshooting guide provided
- [ ] Emergency contacts documented
- [ ] Rollback plan prepared

### Monitoring
- [ ] Error logging configured
- [ ] Log file location documented
- [ ] Rate limiter status monitoring setup
- [ ] Email delivery monitoring configured

---

## Post-Deployment Verification

**Within 1 Hour:**
- [ ] Test one membership registration
- [ ] Verify LGL sync successful
- [ ] Check for PHP errors in logs
- [ ] Verify emails delivering

**Within 24 Hours:**
- [ ] Review all order completions
- [ ] Check LGL sync status for all orders
- [ ] Review error logs
- [ ] Verify cron jobs running

**Within 1 Week:**
- [ ] Monitor renewal reminder emails
- [ ] Review rate limiter statistics
- [ ] Check cache performance
- [ ] User feedback collected

---

## Testing Checklist Summary

### Must Pass (Critical)
- ✅ Membership registration
- ✅ Family member addition
- ✅ Membership renewal
- ✅ LGL API connection
- ✅ Security (nonces, capabilities)
- ✅ Email delivery

### Should Pass (Important)
- ✅ Event registration
- ✅ Class registration
- ✅ Admin interface
- ✅ Error handling
- ✅ Performance benchmarks

### Nice to Have (Optional)
- ✅ Browser compatibility
- ✅ Load testing
- ✅ Mobile testing

---

**Last Updated:** November 17, 2025  
**Plugin Version:** 2.0.0+  
**Test Environment:** LocalWP / Staging

