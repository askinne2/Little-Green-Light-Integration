# Email Blocker Troubleshooting Guide

## Problem: Email Blocker Not Working on WPMU Dev Site

### Quick Diagnosis

The email blocker may not be working because:

1. **WPMU Dev URL doesn't match dev indicators** - The URL `upstateint.tempurl.host` doesn't contain `.local`, `.dev`, `.test`, `staging`, or `development`
2. **Force blocking not enabled** - Manual override setting may not be saved correctly
3. **Email blocker not initialized** - Plugin initialization may have failed
4. **Filter priority issue** - Another plugin may be interfering

---

## Diagnostic Steps

### Step 1: Check Email Blocker Status

Add this temporary diagnostic code to your theme's `functions.php` or create a test plugin:

```php
// TEMPORARY DIAGNOSTIC CODE - Remove after troubleshooting
add_action('admin_notices', function() {
    if (!current_user_can('manage_options')) return;
    
    // Check if EmailBlocker class exists
    if (!class_exists('\UpstateInternational\LGL\Email\EmailBlocker')) {
        echo '<div class="notice notice-error"><p><strong>Email Blocker:</strong> Class not found!</p></div>';
        return;
    }
    
    // Get EmailBlocker instance
    try {
        $container = \UpstateInternational\LGL\Core\Plugin::getInstance(LGL_PLUGIN_FILE)->getContainer();
        $emailBlocker = $container->get('email.blocker');
        
        // Get status
        $status = $emailBlocker->getBlockingStatus();
        $level = $emailBlocker->getBlockingLevel();
        $isEnabled = $emailBlocker->isBlockingEnabled();
        $isForce = $emailBlocker->isForceBlocking();
        $isDev = $emailBlocker->isDevelopmentEnvironment();
        
        // Get environment info
        $host = $_SERVER['HTTP_HOST'] ?? 'Unknown';
        $site_url = get_site_url();
        
        echo '<div class="notice notice-info"><p><strong>Email Blocker Diagnostic:</strong></p>';
        echo '<ul>';
        echo '<li><strong>Blocking Enabled:</strong> ' . ($isEnabled ? 'YES ✅' : 'NO ❌') . '</li>';
        echo '<li><strong>Force Blocking:</strong> ' . ($isForce ? 'YES ✅' : 'NO ❌') . '</li>';
        echo '<li><strong>Development Environment:</strong> ' . ($isDev ? 'YES ✅' : 'NO ❌') . '</li>';
        echo '<li><strong>Blocking Level:</strong> ' . esc_html($level) . '</li>';
        echo '<li><strong>HTTP Host:</strong> ' . esc_html($host) . '</li>';
        echo '<li><strong>Site URL:</strong> ' . esc_html($site_url) . '</li>';
        echo '<li><strong>Is Actively Blocking:</strong> ' . ($status['is_actively_blocking'] ? 'YES ✅' : 'NO ❌') . '</li>';
        echo '<li><strong>Temporarily Disabled:</strong> ' . ($status['is_temporarily_disabled'] ? 'YES ⚠️' : 'NO ✅') . '</li>';
        echo '</ul>';
        echo '</div>';
        
        // Check if wp_mail filter is registered
        global $wp_filter;
        $has_filter = isset($wp_filter['wp_mail']) && isset($wp_filter['wp_mail']->callbacks[999]);
        echo '<div class="notice ' . ($has_filter ? 'notice-success' : 'notice-error') . '">';
        echo '<p><strong>wp_mail Filter Registered:</strong> ' . ($has_filter ? 'YES ✅' : 'NO ❌') . '</p>';
        if (!$has_filter) {
            echo '<p>Email blocker filter is NOT registered! This is the problem.</p>';
        }
        echo '</div>';
        
    } catch (Exception $e) {
        echo '<div class="notice notice-error"><p><strong>Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
    }
});
```

### Step 2: Check Settings in Database

Run this SQL query to check the settings:

```sql
SELECT option_name, option_value 
FROM wp_gp_options 
WHERE option_name LIKE '%email_blocking%' OR option_name LIKE '%lgl_integration_settings%'
ORDER BY option_name;
```

Or check via WordPress:

```php
// Add to functions.php temporarily
add_action('admin_notices', function() {
    $settings = get_option('lgl_integration_settings', []);
    echo '<pre>Email Blocking Settings: ' . print_r([
        'force_email_blocking' => $settings['force_email_blocking'] ?? 'NOT SET',
        'email_blocking_level' => $settings['email_blocking_level'] ?? 'NOT SET',
    ], true) . '</pre>';
});
```

### Step 3: Test Email Blocking

Create a test email to see if it's blocked:

```php
// Add to functions.php temporarily
add_action('admin_init', function() {
    if (!isset($_GET['test_email_blocker'])) return;
    if (!current_user_can('manage_options')) return;
    
    $result = wp_mail(
        'test@example.com',
        'Test Email - Should Be Blocked',
        'This is a test email to verify blocking is working.'
    );
    
    wp_die('Email send result: ' . ($result ? 'SENT (blocking NOT working!)' : 'BLOCKED (blocking working!)'));
});
```

Then visit: `https://upstateint.tempurl.host/wp-admin/?test_email_blocker=1`

---

## Common Issues & Solutions

### Issue 1: WPMU Dev URL Not Recognized as Development

**Problem:** `upstateint.tempurl.host` doesn't match any dev indicators.

**Solution:** Enable "Force Block All Emails" in settings:
1. Go to **LGL Integration → Email Blocking**
2. Check **"Force Block All Emails"**
3. Select blocking level (usually "Block All Emails")
4. Save settings

### Issue 2: Email Blocker Not Initialized

**Problem:** Plugin initialization failed or EmailBlocker wasn't loaded.

**Solution:** 
1. Deactivate and reactivate the plugin
2. Check error logs for initialization errors
3. Verify `src/Email/EmailBlocker.php` exists in the plugin

### Issue 3: Filter Priority Conflict

**Problem:** Another plugin's `wp_mail` filter runs after ours (priority 999).

**Solution:** Check for other plugins modifying `wp_mail`:
```php
global $wp_filter;
if (isset($wp_filter['wp_mail'])) {
    echo '<pre>' . print_r($wp_filter['wp_mail']->callbacks, true) . '</pre>';
}
```

### Issue 4: Settings Not Saving

**Problem:** Settings aren't persisting in the database.

**Solution:**
1. Check database permissions
2. Verify `lgl_integration_settings` option exists
3. Try saving settings again
4. Check for JavaScript errors on settings page

---

## Quick Fix: Force Enable Blocking

If you need to force enable blocking immediately, add this to `wp-config.php`:

```php
// Force email blocking (temporary - use settings page for permanent)
define('LGL_FORCE_EMAIL_BLOCKING', true);
```

Then modify `EmailBlocker::isForceBlocking()` to check this constant:

```php
public function isForceBlocking(): bool {
    // Check constant first (for emergency override)
    if (defined('LGL_FORCE_EMAIL_BLOCKING') && LGL_FORCE_EMAIL_BLOCKING) {
        return true;
    }
    
    return (bool) $this->settingsManager->get('force_email_blocking', false);
}
```

---

## Verification Checklist

- [ ] Email Blocker class exists and is loaded
- [ ] Email Blocker is initialized (`init()` called)
- [ ] `wp_mail` filter is registered (priority 999)
- [ ] Force blocking is enabled OR dev environment is detected
- [ ] Blocking level is set correctly
- [ ] No other plugins interfering with `wp_mail`
- [ ] Settings are saved in database
- [ ] Test email is actually blocked

---

## Debug Logging

Enable debug logging to see what's happening:

1. Enable LGL Debug Mode in **LGL Integration → Settings**
2. Check debug logs in **LGL Integration → Testing Suite → View Logs**
3. Look for entries starting with `LGL Email Blocker:`

Common log entries to look for:
- `LGL Email Blocker: ACTIVE` - Blocker is working
- `LGL Email Blocker: INACTIVE` - Blocker is not active
- `LGL Email Blocker: BLOCKED email` - Email was blocked
- `LGL Email Blocker: ALLOWED` - Email was allowed

---

## Still Not Working?

If none of the above works:

1. **Check plugin version** - Ensure you have the latest version
2. **Check for conflicts** - Deactivate other plugins temporarily
3. **Check PHP errors** - Enable `WP_DEBUG` and check error logs
4. **Check WordPress version** - Ensure WordPress is up to date
5. **Contact support** - Provide diagnostic output from Step 1

