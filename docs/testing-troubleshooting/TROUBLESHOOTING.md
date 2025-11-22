---
layout: default
title: Troubleshooting
---

# Troubleshooting Guide

This guide provides solutions to common issues encountered with the Integrate-LGL WordPress plugin.

## Quick Diagnosis

### Symptom Checklist

Use this checklist to quickly identify your issue:

- [ ] **API Connection Issues** → See [API Connection Problems](#api-connection-problems)
- [ ] **Orders not syncing to LGL** → See [Order Processing Issues](#order-processing-issues)
- [ ] **Memberships not created** → See [Membership Issues](#membership-issues)
- [ ] **Emails not sending** → See [Email Delivery Issues](#email-delivery-issues)
- [ ] **Family members not working** → See [Family Member Issues](#family-member-issues)
- [ ] **Plugin errors/crashes** → See [Plugin Errors](#plugin-errors)
- [ ] **Performance problems** → See [Performance Issues](#performance-issues)

---

## API Connection Problems

### Issue: "Invalid API credentials" or "Connection failed"

**Symptoms:**
- Settings page shows connection test failed
- Orders complete but don't sync to LGL
- Dashboard shows "API Status: Error"

**Solutions:**

1. **Verify API Credentials**
   ```
   WP Admin → LGL Integration → Settings
   ```
   - Check API URL is correct (e.g., `https://api.littlegreenlight.com/api/v1`)
   - Verify API key is valid and complete
   - Look for extra spaces or line breaks
   - API key should be 32+ characters

2. **Test Connection**
   ```
   WP Admin → LGL Integration → Settings → Test Connection button
   ```
   - Should return green success message
   - If fails, check error message for details

3. **Check Debug Log**
   ```
   Plugin directory: src/logs/lgl-api.log
   ```
   - Look for "401 Unauthorized" or "403 Forbidden" errors
   - These indicate invalid credentials

4. **Verify HTTPS**
   - WordPress site must use HTTPS
   - Check `wp-config.php` for `FORCE_SSL_ADMIN`
   - Verify SSL certificate is valid

**Still not working?**
- Contact LGL support to verify API key status
- Check if LGL account has API access enabled
- Verify IP whitelist (if LGL has IP restrictions)

---

### Issue: "Rate limit exceeded"

**Symptoms:**
- Error message mentions "429" or "rate limit"
- Bulk operations fail partway through
- Dashboard shows rate limit warning

**Solutions:**

1. **Check Rate Limiter Status**
   ```
   WP Admin → LGL Integration → Dashboard
   ```
   - View current API usage
   - See when rate limit resets

2. **Wait for Reset**
   - LGL rate limit: 300 calls per 5 minutes
   - Wait for rolling window to clear
   - Plugin automatically delays requests

3. **Reduce Bulk Operations**
   - Process fewer items at once
   - Spread operations over time
   - Use cron jobs for large syncs

4. **Review Rate Limiter Logs**
   ```php
   // In debug log, search for:
   "Rate Limiter:"
   ```

---

## Order Processing Issues

### Issue: Orders complete but don't sync to LGL

**Symptoms:**
- WooCommerce order shows "Completed"
- No LGL constituent created
- No payment recorded in LGL
- Order meta `_lgl_constituent_id` is empty

**Diagnostic Steps:**

1. **Check Order Category**
   ```
   - Is product in correct category?
   - Memberships: "memberships"
   - Events: "events"
   - Classes: "language-class"
   ```

2. **Check Debug Log**
   ```
   Look for order ID in: src/logs/lgl-api.log
   ```
   - Search for "Order #[number]"
   - Look for error messages

3. **Verify Settings**
   ```
   WP Admin → LGL Integration → Settings
   ```
   - Membership levels mapped correctly?
   - Funds imported and selected?
   - Campaigns imported?

4. **Manual Retry**
   - Get order ID
   - Go to: WP Admin → LGL Integration → Testing
   - Run order processing test

**Common Causes:**

| Cause | Solution |
|-------|----------|
| Product not in mapped category | Add product to "memberships", "events", or "language-class" category |
| Invalid API credentials | Fix credentials in settings |
| Missing fund mapping | Import funds and map in settings |
| Network timeout | Check server connection, increase PHP timeout |
| Duplicate email | Plugin finds existing constituent (this is normal) |

---

### Issue: Payment created but not linked to order

**Symptoms:**
- LGL shows payment exists
- Order meta `_lgl_payment_id` is empty
- Can't determine which order created payment

**Solutions:**

1. **Check Order Meta**
   ```php
   // In database or WP Admin → WooCommerce → Orders → (order) → Custom Fields
   - _lgl_constituent_id (should have value)
   - _lgl_payment_id (empty = problem)
   ```

2. **Review API Response**
   - Check debug log for payment creation
   - Look for "Gift ID" in response
   - If ID returned but not saved, may be database issue

3. **Manual Fix**
   ```php
   // Find payment in LGL by amount and date
   // Update order meta manually:
   update_post_meta($order_id, '_lgl_payment_id', $lgl_gift_id);
   ```

---

## Membership Issues

### Issue: Membership not created in LGL

**Symptoms:**
- User has `lgl_id` (constituent created)
- No membership record in LGL
- User meta `lgl_membership_level_id` is empty

**Solutions:**

1. **Check Membership Level Mapping**
   ```
   WP Admin → LGL Integration → Settings → Membership Levels
   ```
   - Are LGL levels imported?
   - Is WooCommerce product mapped to LGL level?
   - Product category must be "memberships"

2. **Verify LGL Membership Level ID**
   ```
   - Check settings for numeric ID
   - Must match ID in LGL system
   - Import from LGL to ensure correct IDs
   ```

3. **Check API Permissions**
   - LGL API key must have membership permissions
   - Test with LGL support if unsure

4. **Manual Membership Creation**
   ```
   WP Admin → LGL Integration → Testing
   - Run "Add Membership" test
   - Enter user's lgl_id
   - Select membership level
   ```

---

### Issue: Membership renewal not extending date

**Symptoms:**
- Renewal order completes
- Payment recorded
- Membership date doesn't extend

**Solutions:**

1. **Check Product Category**
   - Must be in "memberships" category
   - Check product is tagged as renewal

2. **Verify User Membership Meta**
   ```php
   - user-membership-renewal-date (current date)
   - Should extend by +1 year after renewal
   ```

3. **Check Order Processing**
   - Look in debug log for "Membership Renewal"
   - Verify renewal handler ran

4. **Force Renewal**
   ```php
   // Update renewal date manually if needed:
   update_user_meta($user_id, 'user-membership-renewal-date', date('Y-m-d', strtotime('+1 year')));
   update_user_meta($user_id, 'user-membership-status', 'active');
   ```

---

## Email Delivery Issues

### Issue: Confirmation emails not sending

**Symptoms:**
- Order completes successfully
- User doesn't receive email
- No email in spam folder

**Solutions:**

1. **Check Email Blocking Settings**
   ```
   WP Admin → LGL Integration → Email Blocking
   ```
   - Is email blocking enabled?
   - Check whitelist/blacklist
   - Verify environment detection

2. **Check WooCommerce Email Settings**
   ```
   WP Admin → WooCommerce → Settings → Emails
   ```
   - Verify emails are enabled
   - Check "From" email address
   - Test email delivery

3. **Check WordPress Mail Function**
   - Install WP Mail SMTP plugin
   - Configure SMTP settings
   - Test mail delivery

4. **Review Email Logs**
   - Check server mail logs
   - Look for bounces or blocks
   - Verify SPF/DKIM records

**Development Environment:**
- Plugin automatically blocks emails in local environments
- Add recipient to whitelist to receive test emails
- Check `EmailBlocker::shouldBlockEmail()` logic

---

## Family Member Issues

### Issue: Can't add family member

**Symptoms:**
- Form submits but user not created
- Error message shown
- Family slots available but still fails

**Solutions:**

1. **Check Available Slots**
   ```php
   // User meta:
   - user_total_family_slots_purchased (should be > 0)
   - user_used_family_slots (should be < total)
   - user_available_family_slots (should be > 0)
   ```

2. **Sync Family Slots**
   ```php
   // Run in WP Admin → Tools → Debug:
   \UpstateInternational\LGL\LGL\Helper::getInstance()->syncFamilySlots($parent_user_id);
   ```

3. **Check JetEngine Relationships**
   - Verify `jet_rel_user_family_members` relationship exists
   - Check relationship ID in settings
   - Test relationship creation manually

4. **Review Form Validation**
   - Check required fields filled
   - Email must be unique
   - Parent user must have active membership

---

### Issue: LGL relationship not created

**Symptoms:**
- WordPress user created successfully
- No constituent relationship in LGL
- User meta `lgl_family_relationship_id` is empty

**Solutions:**

1. **Verify Both Users Have LGL IDs**
   ```php
   // Check user meta:
   - Parent: lgl_id (required)
   - Child: lgl_id (required)
   ```

2. **Check API Response**
   - Look in debug log for "constituent_relationships"
   - Check for API errors

3. **Manual Relationship Creation**
   - Log into LGL CRM
   - Navigate to parent constituent
   - Add relationship manually
   - Update WordPress user meta with relationship ID

---

## Plugin Errors

### Issue: "Fatal error: Allowed memory size exhausted"

**Symptoms:**
- White screen of death
- PHP error in logs
- Plugin crashes on activation

**Solutions:**

1. **Increase PHP Memory Limit**
   ```php
   // In wp-config.php:
   define('WP_MEMORY_LIMIT', '256M');
   ```

2. **Check for Circular Dependencies**
   - Should be resolved in v2.0+
   - Check debug log for initialization loops

3. **Disable Debug Mode**
   ```
   WP Admin → LGL Integration → Settings → Advanced
   - Turn off debug mode
   - Clear debug log
   ```

4. **Clear All Caches**
   ```
   WP Admin → LGL Integration → Dashboard → Clear Cache
   ```

---

### Issue: "Class not found" or autoloader errors

**Symptoms:**
- PHP error about missing class
- Plugin features not working
- Admin pages blank

**Solutions:**

1. **Run Composer Install**
   ```bash
   cd wp-content/plugins/Integrate-LGL
   composer install --no-dev
   ```
   Or use the refresh autoloader script:
   ```bash
   ./refresh-autoloader.sh
   ```

2. **Verify Autoloader Exists**
   ```
   Check file exists: vendor/autoload.php
   ```

3. **Check File Permissions**
   ```bash
   # Ensure plugin files are readable
   chmod -R 755 wp-content/plugins/Integrate-LGL
   ```

---

### Issue: JavaScript errors in admin

**Symptoms:**
- AJAX requests fail
- Settings don't save
- Buttons don't work
- Browser console shows errors

**Solutions:**

1. **Clear Browser Cache**
   - Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
   - Clear all browser cache
   - Try incognito/private mode

2. **Check for JavaScript Conflicts**
   - Disable other plugins temporarily
   - Test with default WordPress theme
   - Check browser console for specific errors

3. **Verify Assets Loading**
   ```
   View page source, check for:
   - admin-bundle.js loading
   - No 404 errors
   ```

4. **Check Nonce**
   - JavaScript errors about "nonce" indicate security token issue
   - Refresh admin page
   - Clear WordPress object cache

---

## Performance Issues

### Issue: Slow admin pages

**Symptoms:**
- Dashboard takes 5+ seconds to load
- Settings page sluggish
- Timeout errors

**Solutions:**

1. **Enable Object Caching**
   - Install Redis or Memcached
   - Use persistent object cache drop-in
   - Significant performance improvement

2. **Check Database Queries**
   - Install Query Monitor plugin
   - Identify slow queries
   - Look for N+1 patterns

3. **Clear Transients**
   ```
   WP Admin → LGL Integration → Dashboard → Clear Cache
   ```

4. **Reduce API Calls**
   - Cache is enabled by default
   - Increase cache TTL if needed
   - Check rate limiter status

---

### Issue: High memory usage

**Symptoms:**
- PHP memory errors
- Server slow during order processing
- Bulk operations crash

**Solutions:**

1. **Process in Batches**
   - Limit bulk operations to 50-100 items
   - Use cron for large syncs
   - Add delays between requests

2. **Increase PHP Memory**
   ```php
   // wp-config.php
   define('WP_MEMORY_LIMIT', '512M');
   ```

3. **Clear Logs**
   ```
   Check size of: src/logs/lgl-api.log
   Archive or delete if > 100MB
   ```

---

## Debug Mode

### Enabling Debug Mode

1. **Via Settings**
   ```
   WP Admin → LGL Integration → Settings → Advanced
   - Enable "Debug Mode"
   - Save settings
   ```

2. **Via wp-config.php**
   ```php
   define('LGL_DEBUG_MODE', true);
   ```

### Reading Debug Logs

**Log Location:**
```
wp-content/plugins/Integrate-LGL/src/logs/lgl-api.log
```

**What to Look For:**
- API request/response pairs
- Error messages with context
- Timing information
- User/order IDs being processed

**Log Format:**
```
[2025-01-15 12:34:56] LGL API Request: POST /constituents.json
[2025-01-15 12:34:57] LGL API Response: {"id": 12345, "first_name": "John"}
```

**Security Note:**
- Debug logs may contain sensitive data
- Disable debug mode in production
- Don't share logs publicly without sanitizing

---

## Getting Help

### Before Contacting Support

1. **Gather Information**
   - WordPress version
   - PHP version
   - Plugin version
   - Error messages (exact text)
   - Steps to reproduce
   - Debug log excerpt (sanitized)

2. **Run Diagnostics**
   ```
   WP Admin → LGL Integration → Testing → System Test
   ```
   - Save output
   - Include in support request

3. **Check Documentation**
   - README.md - Plugin overview
   - SECURITY.md - Security practices
   - API-REFERENCE.md - API details
   - MANUAL-TESTING-GUIDE.md - Testing procedures

### Support Contacts

**Plugin Issues:**
- Developer: [Your contact info]
- GitHub Issues: [Repository URL]

**LGL API Issues:**
- LGL Support: support@littlegreenlight.com
- LGL Documentation: https://support.littlegreenlight.com

**WordPress/WooCommerce:**
- WordPress Support: https://wordpress.org/support/
- WooCommerce Support: https://woocommerce.com/support/

---

## Common Error Messages

### "Nonce verification failed"

**Cause:** Security token expired or invalid

**Solution:**
- Refresh admin page
- Clear browser cache
- Check server time is correct

---

### "Insufficient permissions"

**Cause:** User lacks required capability

**Solution:**
- Login as administrator
- Check user role has `manage_options` capability

---

### "LGL API URL or API key not configured"

**Cause:** Settings missing or invalid

**Solution:**
- Configure settings: WP Admin → LGL Integration → Settings
- Ensure both API URL and API key are filled
- Test connection

---

### "Order not found" or "User not found"

**Cause:** Data doesn't exist or ID is invalid

**Solution:**
- Verify order/user ID is correct
- Check if order/user was deleted
- Review test data vs production data

---

## Preventive Maintenance

### Weekly Tasks
- [ ] Review error logs
- [ ] Check API rate limiter usage
- [ ] Verify cron jobs running
- [ ] Test one membership registration

### Monthly Tasks
- [ ] Clear old debug logs
- [ ] Review and archive test orders
- [ ] Check disk space
- [ ] Update plugin if new version available

### Quarterly Tasks
- [ ] Full backup
- [ ] Security audit
- [ ] Performance review
- [ ] Settings review

---

**Last Updated:** November 17, 2025  
**Plugin Version:** 2.0.0+

