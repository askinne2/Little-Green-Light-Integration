# Development Testing Guide

## Overview

This guide explains how to safely test the membership renewal system in local development without sending emails to real members.

## 🛡️ Email Protection System

### EmailBlocker

The `EmailBlocker` class automatically prevents emails from being sent in development environments while logging all attempts for review.

**Features:**
- ✅ Automatic detection of development environments (.local, localhost, .dev, etc.)
- ✅ Manual override toggle to force-block all outgoing mail
- ✅ Logs all blocked emails with full details
- ✅ Stores last 50 blocked emails for admin review
- ✅ Whitelist support for admin testing
- ✅ Temporary disable option for testing

**Access:** LGL Integration → Email Blocking

### How It Works

1. **Automatic Detection** - Detects development environment based on:
   - Domain indicators (.local, localhost, .dev, .test, staging, development)
   - Local IP addresses
2. **Manual Override** - Force blocking with a single checkbox, regardless of environment
3. **Email Blocking** - All emails are intercepted and:
   - Logged to error_log
   - Stored in database (last 50)
   - Displayed in admin interface
4. **Whitelisting** - Admin email is always whitelisted, allowing test emails to be sent to you

## 🧪 Membership Testing Tools

### Access

**Location:** LGL Integration → Testing Tools

### Tab 1: Dry Run Report

Simulates the daily cron job without sending any emails.

**What It Shows:**
- Total members analyzed
- WooCommerce subscription members (skipped)
- Plugin-managed members
- Emails that would be sent today
- Members not requiring action

**Use Cases:**
- Verify renewal logic is working correctly
- See which members would receive emails today
- Debug renewal date calculations
- Understand which members are managed by which system

**How to Use:**
1. Go to LGL Integration → Testing Tools
2. Click "Dry Run Report" tab
3. Click "Run Dry Run Test"
4. Review results table

### Tab 2: Email Preview

Preview renewal emails without sending them.

**Features:**
- Preview any email interval (30d, 14d, 7d, 0d, -7d, -30d)
- Use real member data or sample data
- See both rendered HTML and raw source
- Test variable replacements

**Use Cases:**
- Review email content before going live
- Test template changes
- Verify variable replacements
- Check mobile/desktop rendering

**How to Use:**
1. Select a user (optional) or use sample data
2. Choose email interval
3. Click "Preview Email"
4. Review rendered email in iframe

### Tab 3: Test Specific User

Run renewal logic for a single user to see what would happen.

**Features:**
- Select any member from dropdown
- See complete renewal processing results
- Debug individual member issues
- Verify strategy detection (WC vs Plugin)

**Use Cases:**
- Debug why a specific user isn't getting emails
- Verify renewal date calculations for one user
- Test migration results for specific members
- Troubleshoot edge cases

**How to Use:**
1. Select a user from dropdown
2. Click "Test User"
3. Review detailed results

### Tab 4: Send to Admin

Actually send a test email to your admin email address.

**Features:**
- Emails automatically whitelisted to admin email
- Use real member data or sample data
- Test all 6 email intervals
- Verify actual email delivery

**Use Cases:**
- Test email delivery system
- Check spam filter behavior
- Verify email formatting in real inbox
- Test on mobile devices

**How to Use:**
1. Verify your email address (defaults to admin_email)
2. Select email interval
3. Optionally select a user for real data
4. Click "Send Test Email"
5. Check your inbox (and spam folder)

## 📋 Testing Checklist

### Before Deployment

- [ ] Run dry run test to verify no unexpected emails would be sent
- [ ] Preview all 6 email templates
- [ ] Send test emails to yourself for all 6 intervals
- [ ] Test on mobile device (forward test email)
- [ ] Verify variables are replaced correctly
- [ ] Test with real member data
- [ ] Check blocked emails log is empty of errors
- [ ] Verify WC subscription detection works
- [ ] Test grace period logic
- [ ] Verify cron job timing

### Common Testing Scenarios

#### Scenario 1: New Email Template
1. Edit template in Renewal Settings
2. Save settings
3. Preview email to verify changes
4. Send test to admin
5. Check rendering in real inbox
6. Run dry run to see affected members

#### Scenario 2: New Member Setup
1. Create/update test member with renewal date
2. Use "Test Specific User" to verify processing
3. Adjust renewal date to trigger different intervals
4. Verify correct emails would be sent

#### Scenario 3: Migration Testing
1. Run migration utility on staging
2. Use "Test Specific User" on migrated members
3. Run dry run to see overall impact
4. Verify no unexpected emails

#### Scenario 4: Cron Job Testing
1. Run dry run to simulate cron execution
2. Review what would happen today
3. Check for errors in blocked emails log
4. Verify member counts match expectations

## 🔧 Troubleshooting

### Emails Not Sending (Even to Admin)

**Check:**
1. Is EmailBlocker active? (LGL Integration → Email Blocking)
2. Is admin email whitelisted? (It should be automatic)
3. Check error_log for "BLOCKED" or "ALLOWED" messages
4. Verify wp_mail() is working: send test via WP core

**Solution:**
```php
// Temporarily disable blocker (5 minutes)
\UpstateInternational\LGL\Email\EmailBlocker::temporarilyDisable(300);
```

### Preview Shows Wrong Content

**Check:**
1. Verify settings were saved (check database)
2. Check for PHP errors in email generation
3. Verify template variables are correct
4. Clear any caching plugins

### Dry Run Shows No Members

**Check:**
1. Do members have renewal dates set?
2. Are members in correct roles (ui_member, ui_patron_owner)?
3. Check for database errors in logs
4. Verify member migration was run

### Test Email Not Received

**Check:**
1. Spam folder
2. Email filters
3. Server email configuration
4. Check blocked emails log - was it actually blocked?
5. Try different email address

## 🚀 Production Deployment

### Pre-Launch Checklist

1. **Verify Settings**
   - [ ] All email templates configured
   - [ ] Grace period set correctly
   - [ ] Notification intervals correct
   - [ ] Test email functionality

2. **Test Migration**
   - [ ] Run migration on staging first
   - [ ] Verify member counts
   - [ ] Check sample members manually
   - [ ] Run dry run on staging

3. **Email Validation**
   - [ ] Preview all templates
   - [ ] Send test to admin
   - [ ] Check mobile rendering
   - [ ] Verify all variables work

4. **Monitoring Setup**
   - [ ] Review blocked emails log
   - [ ] Check error_log for issues
   - [ ] Monitor first 48 hours closely
   - [ ] Have rollback plan ready

### Launch Day

1. Deploy plugin to production
2. Verify EmailBlocker is INACTIVE (production environment)
3. Check first cron execution (9 AM)
4. Monitor blocked emails log (should be empty/inactive)
5. Verify first emails are sent correctly
6. Check member reports for issues

### Post-Launch Monitoring

**Day 1:**
- Check error logs every 2 hours
- Verify cron execution
- Monitor email delivery

**Week 1:**
- Review daily cron results
- Check for member complaints
- Verify all intervals working

**Month 1:**
- Review statistics
- Gather feedback
- Optimize as needed

## 📊 Understanding Reports

### Dry Run Report

```
Total Members Analyzed: 827
WooCommerce Subscription Members (skipped): 150
Plugin-Managed Members: 677
Emails That Would Be Sent: 23
```

**Interpretation:**
- 827 total members in system
- 150 have active WC subscriptions (managed by WooCommerce)
- 677 are managed by plugin renewal system
- 23 members would receive emails today (on notification interval)

### Email Preview

Shows exactly what members will receive, including:
- Subject line with variables replaced
- Full HTML content with styling
- All links and formatting
- Footer and header templates

### Test Specific User Results

Shows complete processing for one user:
- Strategy determination (WC vs Plugin)
- Renewal date calculation
- Days until renewal
- Email type that would be sent
- Any errors or warnings

## 🎯 Best Practices

1. **Always Test First**
   - Use dry run before any major changes
   - Preview emails after editing templates
   - Test with real member data

2. **Monitor Blocked Emails**
   - Check daily in development
   - Look for unexpected blocks
   - Verify admin emails are working

3. **Use Staging Environment**
   - Test migrations on staging first
   - Verify settings before production
   - Run dry runs on staging data

4. **Regular Verification**
   - Weekly dry runs to verify system health
   - Monthly review of email templates
   - Quarterly testing of all intervals

5. **Documentation**
   - Document any customizations
   - Keep notes on member feedback
   - Track template changes

## 🔒 Security Considerations

- Testing tools are admin-only (capability: `manage_options`)
- Nonces protect all AJAX actions
- Email addresses are sanitized
- Preview content is escaped
- Whitelist is stored securely

## 💡 Tips & Tricks

1. **Quick Test Workflow:**
   ```
   Edit Template → Preview → Send to Admin → Dry Run → Deploy
   ```

2. **Finding Problem Members:**
   - Use "Test Specific User" with dropdown
   - Filter by renewal date in report
   - Check error logs for user IDs

3. **Testing All Intervals:**
   - Use "Send to Admin" for each interval
   - Create labels in email for testing
   - Archive test emails for reference

4. **Development Workflow:**
   - Keep blocked emails log open during development
   - Use dry run frequently
   - Test edge cases (no renewal date, past due, etc.)

## 📞 Support

For issues or questions:
1. Check this guide first
2. Review error_log for details
3. Check blocked emails log
4. Use dry run for debugging
5. Contact development team with specific error messages

## 🔗 Related Documentation

- [Dual Renewal Implementation](./DUAL-RENEWAL-IMPLEMENTATION-COMPLETE.md)
- [Migration Guide](./MIGRATION-GUIDE.md)
- [Settings Overhaul Index](./SETTINGS-OVERHAUL-INDEX.md)
- [Testing Suite Guide](./TESTING-SUITE-GUIDE.md)

