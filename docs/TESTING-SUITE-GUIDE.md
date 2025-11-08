# LGL Testing Suite Guide

**Version:** 2.1.0  
**Date:** November 8, 2025

---

## Overview

The LGL Integration plugin includes a comprehensive testing suite accessible via both the WordPress admin interface and front-end shortcodes. All tests run against the **LIVE LGL API** and will create/update real data.

---

## Admin Testing Interface

### Access

Navigate to: `wp-admin/admin.php?page=lgl-testing`

### Available Tests

#### 1. Connection Test
- **Purpose:** Verify API connectivity and authentication
- **Action:** Sends a test request to LGL API
- **Success Criteria:** Returns API version and connection status

#### 2. Add Constituent
- **Purpose:** Create a new constituent from WordPress user data
- **Default User:** ID 1214 (Andrew Skinner)
- **Data Source:** User meta (billing info, profile data)
- **Creates:**
  - Constituent record in LGL
  - Email address
  - Phone number
  - Street address
  - Custom field: `wordpress_user_id`
- **Updates:** `lgl_id` in WordPress user meta

#### 3. Update Constituent
- **Purpose:** Update existing constituent information
- **Requirements:** User must have `lgl_id` in user meta
- **Updates:** Name, email, and other profile data

#### 4. Add Membership
- **Purpose:** Add membership to existing constituent
- **Default Variation:** ID 68386 (Individual - $75)
- **Requirements:**
  - User must have `lgl_id`
  - Product variation must have `_lgl_membership_fund_id` meta
- **Creates:** Membership record with:
  - Membership level ID
  - Amount
  - Start date
  - Recurring status

#### 5. Update Membership
- **Purpose:** Update existing membership details
- **Status:** Structure in place (requires membership ID from LGL)

#### 6. Event Registration
- **Purpose:** Test event registration flow
- **Default Variation:** ID 83556
- **Validates:** Product exists and pricing
- **Note:** Creates payment + activity records in LGL

#### 7. Class Registration
- **Purpose:** Test class registration flow
- **Default Product:** ID 86825
- **Validates:** Product exists and LGL fund mapping
- **Note:** Creates payment + activity records in LGL

#### 8. Full Test Suite
- **Purpose:** Run all tests sequentially
- **Duration:** Varies based on API response times

---

## Test Configuration

### Default Test User
```
User ID: 1214
Name: Andrew Skinner
Email: andrew@hispanicalliancesc.com
Phone: 8653120285
Address: 57 Blake Street, Greenville, SC 29605
LGL ID: 970972
```

### Product IDs
```
Membership Variation: 68386 (Individual - $75)
Event Variation: 83556
Class Product: 86825
```

---

## Front-End Testing Shortcodes

### Available Shortcodes

#### 1. Connection Test
```
[test_lgl_connection]
```
Tests API connectivity from the front-end.

#### 2. Debug Membership Test
```
[debug_membership_test run="yes"]
```
Creates a test WooCommerce order and processes membership flow:
- Creates test user and order
- Processes through OrderProcessor → MembershipOrderHandler
- Attempts LGL API sync
- Displays detailed results

**Location:** `test/debug-membership-test.php`

#### 3. Test Flow
```
[lgl_test_flow]
```
General testing flow shortcode.

**Location:** `test/test-shortcode.php`

#### 4. Phase 5 Memberships Test
```
[test_phase5_memberships]
```
Tests Phase 5 membership architecture.

**Location:** `test/test-phase5-memberships.php`

---

## Technical Details

### AJAX Handler

All admin tests use:
```
Action: lgl_run_test
Nonce: lgl_admin_nonce
Parameters:
  - test_type: string
  - wordpress_user_id: int (optional, defaults to 1214)
  - variation_product_id: int (optional)
  - class_product_id: int (optional)
```

### Test Methods

Each test is implemented in `src/Admin/TestingHandler.php`:
- `runAddConstituentTest()`
- `runUpdateConstituentTest()`
- `runAddMembershipTest()`
- `runUpdateMembershipTest()`
- `runEventRegistrationTest()`
- `runClassRegistrationTest()`

### Services Used

Tests interact with:
- `\UpstateInternational\LGL\LGL\Constituents` - Constituent management
- `\UpstateInternational\LGL\LGL\Connection` - API communication
- `\UpstateInternational\LGL\LGL\Helper` - Utility functions
- `\UpstateInternational\LGL\Memberships\MembershipRegistrationService` - Membership logic

---

## Logging

All test operations are logged to:
```
includes/logs/lgl-api.log
```

Enable debug mode in settings to see detailed request/response data.

---

## API Considerations

### Multi-Request Pattern

The plugin uses a multi-request pattern for constituent creation:
1. Create constituent (personal data)
2. Add email address (separate request)
3. Add phone number (separate request)
4. Add street address (separate request)
5. Add membership (separate request)

This mirrors the legacy system and ensures reliable data synchronization with LGL.

### Rate Limiting

Be mindful of API rate limits when running multiple tests in quick succession.

### Data Persistence

**⚠️ IMPORTANT:** All tests create/update REAL data in your LGL instance. Use with caution in production environments.

---

## Troubleshooting

### "User not found"
- Verify the WordPress user ID exists
- Check that user has required meta fields populated

### "No LGL ID found"
- Run "Add Constituent" test first
- Verify `lgl_id` exists in user meta

### "No _lgl_membership_fund_id found"
- Check product variation has the required meta field
- Update product in WooCommerce → Products

### HTTP 422/500 Errors
- Check `lgl-api.log` for detailed error messages
- Verify API credentials in Settings
- Ensure all required fields are populated

### "Class not found"
- Run `composer dump-autoload -o` in plugin directory
- Verify modern architecture is loaded

---

## Future Enhancements

1. **Batch Testing:** Run tests on multiple users
2. **Custom Payloads:** Override default test data via UI forms
3. **Scheduled Tests:** Cron-based health checks
4. **Test History:** Store test results in database
5. **Export Results:** Download test reports as CSV/JSON

---

## Related Documentation

- `lgl_optimization_checklist.md` - Plugin modernization phases
- `MODERNIZATION-COMPLETE.md` - Architecture overview
- `data-contracts.md` - LGL API schema reference

---

*Generated: November 8, 2025*  
*Plugin Version: 2.1.0*

