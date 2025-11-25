# LGL Plugin Logging Strategy

## Overview
This document outlines the logging strategy for the LGL Integration plugin to ensure consistent, manageable, and useful logging across all components.

## Logging Principles

### 1. **Log Levels**
- **ERROR**: Critical failures that prevent functionality (API failures, data corruption, exceptions)
- **WARNING**: Important issues that don't break functionality but need attention (deprecated calls, fallbacks)
- **INFO**: Important business events (order processed, membership created, user synced)
- **DEBUG**: Detailed technical information for troubleshooting (API requests/responses, data transformations)

### 2. **What to Log**

#### ✅ **SHOULD LOG** (INFO level):
- Order processing start/completion
- Membership creation/updates
- User sync operations
- API transaction results (success/failure)
- Critical business events
- Configuration changes

#### ⚠️ **CONDITIONAL LOG** (WARNING level):
- Fallback mechanisms used
- Deprecated feature usage
- Rate limiting events
- Retry attempts

#### ❌ **SHOULD NOT LOG** (Remove or reduce to DEBUG):
- Every function call
- Loop iterations
- Filter/hook registrations
- Initialization messages (unless critical)
- Every email evaluation
- Internal state changes
- Data transformations (unless errors)

### 3. **Logging Methods**

#### Use Helper Methods:
```php
$helper = Helper::getInstance();
$helper->error('Critical error message', $data);    // Errors only
$helper->warning('Warning message', $data);          // Warnings + Errors
$helper->info('Important event', $data);            // Info + Warnings + Errors
$helper->debug('Detailed debug info', $data);       // All levels
```

#### ❌ **DO NOT USE**:
- `error_log()` directly (bypasses level filtering)
- `var_dump()` / `print_r()` for debugging
- Echo statements for logging

## Implementation Plan

### Phase 1: Core Infrastructure ✅ (COMPLETED)
- [x] Add log level support to Helper class
- [x] Create Debug Log Viewer in WP Admin
- [x] Add log level settings in admin panel
- [x] Reduce Email Blocker verbosity

### Phase 2: Remove Direct error_log() Calls
**Files to update:**
- `includes/woocommerce/subscription-renewal.php` (12 calls)
- `includes/email/daily-email.php` (5 calls)
- `includes/email/email-blocker.php` (5 calls)
- `includes/lgl-connections.php` (1 call)
- `includes/lgl-helper.php` (2 calls)
- `src/Admin/functions.php` (1 call)

**Action**: Replace all `error_log()` with appropriate Helper methods

### Phase 3: Reduce Verbosity by Component ✅ **COMPLETED**

#### High Priority (Most Verbose):
1. **WooCommerce Order Processing** (~150 calls) ✅ **COMPLETED**
   - `src/WooCommerce/OrderProcessor.php` (58 calls → optimized) ✅
   - `src/WooCommerce/AsyncOrderProcessor.php` (29 calls → optimized) ✅
   - `src/WooCommerce/MembershipOrderHandler.php` (41 calls → optimized) ✅
   - **Strategy**: Log only start/completion, errors, and important state changes

2. **LGL API Operations** (~200 calls) ✅ **COMPLETED**
   - `src/LGL/Connection.php` (136 calls → optimized) ✅
   - `src/LGL/Constituents.php` (48 calls → optimized) ✅
   - `src/LGL/WpUsers.php` (32 calls → optimized) ✅
   - **Strategy**: Log API requests/responses only on errors or at DEBUG level

3. **JetFormBuilder Actions** (~200 calls) ✅ **COMPLETED**
   - Multiple action files (19-45 calls each → optimized) ✅
   - **Strategy**: Log only action execution start/completion and errors

4. **Membership Management** (~100 calls) ✅ **COMPLETED**
   - `src/Memberships/MembershipRegistrationService.php` (43 calls → optimized) ✅
   - `src/Memberships/MembershipRenewalManager.php` (12 calls → optimized) ✅
   - **Strategy**: Log business events (created, renewed, cancelled) at INFO level

#### Medium Priority:
5. **Settings & Admin** (~100 calls) ✅ **COMPLETED**
   - `src/Admin/SettingsManager.php` (71 calls → optimized) ✅
   - `src/Admin/SettingsHandler.php` (28 calls → optimized) ✅
   - **Strategy**: Log only configuration changes and errors

6. **Legacy Includes** (~100 calls) ✅ **COMPLETED**
   - `includes/lgl-wp-users.php` (61 calls → optimized) ✅ **COMPLETED**
   - `includes/lgl-payments.php` (43 calls → optimized) ✅ **COMPLETED**
   - **Strategy**: Migrate to Helper methods, reduce verbosity

### Phase 4: Log Level Assignment

#### ERROR Level:
- API connection failures
- Data validation failures
- Critical exceptions
- Payment processing failures

#### WARNING Level:
- API rate limiting
- Fallback mechanisms
- Deprecated feature usage
- Retry attempts

#### INFO Level:
- Order processed successfully
- Membership created/updated
- User synced to LGL
- Configuration saved
- Email sent/blocked

#### DEBUG Level:
- API request/response details
- Data transformations
- Internal state changes
- Filter/hook execution

## File-by-File Action Plan

### Critical Files (Start Here):

1. **src/LGL/Helper.php**
   - ✅ Already updated with log levels
   - ✅ Remove direct error_log() calls (keep only for critical errors)

2. **src/LGL/Connection.php** (136 calls)
   - Change API request/response logging to DEBUG level
   - Keep errors at ERROR level
   - Remove verbose request/response dumps

3. **src/WooCommerce/OrderProcessor.php** (58 calls)
   - Log order processing start/completion at INFO
   - Log errors at ERROR
   - Remove step-by-step debug logs

4. **includes/woocommerce/subscription-renewal.php** (12 error_log calls)
   - Replace error_log() with Helper methods
   - Reduce verbosity

### Migration Checklist

For each file:
- [ ] Replace `error_log()` with `Helper::getInstance()->error()` or appropriate level
- [ ] Review debug() calls - change to appropriate level (info/warning/error)
- [ ] Remove verbose loop/iteration logs
- [ ] Remove initialization logs (unless critical)
- [ ] Keep only business-critical events at INFO level
- [ ] Move technical details to DEBUG level

## Testing Strategy

1. **Enable DEBUG level** - Verify all logs appear
2. **Set to INFO level** - Verify only important events appear
3. **Set to WARNING level** - Verify only warnings/errors appear
4. **Set to ERROR level** - Verify only errors appear
5. **Check PHP error log** - Should be minimal/noise-free

## Success Metrics

- ✅ No direct `error_log()` calls (except Helper class)
- ✅ Log file size manageable (< 1MB per day in production)
- ✅ PHP error log clean (no plugin noise)
- ✅ Important events visible at INFO level
- ✅ Technical details available at DEBUG level
- ✅ Production-ready logging (ERROR/WARNING only)

## Notes

- Legacy includes (`includes/`) should be migrated to use Helper methods
- Consider deprecating legacy files if they're not actively used
- Monitor log file size and adjust rotation if needed
- Review logs weekly to identify patterns/issues

